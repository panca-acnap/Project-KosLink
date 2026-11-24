<?php
require 'koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['role']) && $_SESSION['role'] !== 'mitra';
$id_user      = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? $_SESSION['id_pencari'] ?? $_SESSION['id'] ?? 0);
$nama_user    = $_SESSION['nama'] ?? $_SESSION['nama_lengkap'] ?? 'Pengguna KosLink';

// Helper deteksi kolom tabel otomatis (aman untuk PHP 8+ strict mode)
function getTableColumns($conn, $table) {
    $cols = [];
    try {
        $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $cols[] = $r['Field'];
            }
        }
    } catch (mysqli_sql_exception $e) {
        return [];
    }
    return $cols;
}

// Cek hunian aktif user dari tabel transaksi (status 'lunas') sesuai skema V2
$hunian_aktif = null;
if ($is_logged_in && $id_user > 0) {
    $hunian_aktif = db_one(
        "SELECT t.*, k.nama_kamar AS no_kamar
         FROM transaksi t
         INNER JOIN kamar k ON t.id_kamar = k.id_kamar
         WHERE t.id_pengguna = ? AND t.status_pembayaran = 'lunas'
         ORDER BY t.tanggal_transaksi DESC LIMIT 1",
        'i',
        [$id_user]
    );
}
$cols_prop       = getTableColumns($conn, 'properti_kos');
$pk_prop         = in_array('id_properti', $cols_prop) ? 'id_properti' : 'id_kos';
$col_nama_kos    = in_array('nama_kos', $cols_prop) ? 'nama_kos' : 'nama_properti';
$col_alamat_kos  = in_array('alamat', $cols_prop) ? 'alamat' : (in_array('lokasi', $cols_prop) ? 'lokasi' : '');
$col_jenis_kos   = in_array('jenis_kos', $cols_prop) ? 'jenis_kos' : (in_array('tipe_kos', $cols_prop) ? 'tipe_kos' : '');

$cols_kamar      = getTableColumns($conn, 'kamar');
$pk_kamar        = in_array('id_kamar', $cols_kamar) ? 'id_kamar' : 'id';
$fk_kamar        = in_array('id_properti', $cols_kamar) ? 'id_properti' : 'id_kos';
$col_no_kamar    = in_array('nomor_kamar', $cols_kamar) ? 'nomor_kamar' : (in_array('nama_kamar', $cols_kamar) ? 'nama_kamar' : 'tipe_kamar');
$col_tipe_kamar  = in_array('tipe_kamar', $cols_kamar) ? 'tipe_kamar' : $col_no_kamar;
$col_status_kmr  = in_array('status_kamar', $cols_kamar) ? 'status_kamar' : (in_array('status', $cols_kamar) ? 'status' : '');
$col_fasilitas   = in_array('fasilitas', $cols_kamar) ? 'fasilitas' : '';
$col_foto        = in_array('foto', $cols_kamar) ? 'foto' : (in_array('foto_kamar', $cols_kamar) ? 'foto_kamar' : '');

$daftar_kos = [];
if (count($cols_kamar) > 0 && count($cols_prop) > 0) {
    $sql_list = "SELECT k.*, p.`$col_nama_kos` AS nama_kos_prop"
              . ($col_alamat_kos !== '' ? ", p.`$col_alamat_kos` AS alamat_prop" : ", 'Jimbaran, Kuta Selatan' AS alamat_prop")
              . ($col_jenis_kos !== '' ? ", p.`$col_jenis_kos` AS jenis_kos_prop" : ", 'Campur' AS jenis_kos_prop")
              . " FROM kamar k INNER JOIN properti_kos p ON k.`$fk_kamar` = p.`$pk_prop` ORDER BY k.`$pk_kamar` DESC";
    $q_list = @mysqli_query($conn, $sql_list);
    if ($q_list) {
        while ($row = mysqli_fetch_assoc($q_list)) {
            $status_val = $col_status_kmr !== '' ? strtolower(trim($row[$col_status_kmr])) : 'tersedia';
            $row['is_tersedia'] = !in_array($status_val, ['terisi', 'occupied', 'disewa', 'penuh', 'aktif']);
            $daftar_kos[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KosLink - Temukan Kos Impianmu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- NAVBAR ATAS -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-8">
                <a href="index.php" class="flex items-center gap-2.5">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                        <i class="fa-solid fa-building text-sm"></i>
                    </div>
                    <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
                </a>
                <nav class="hidden md:flex items-center gap-6 text-xs font-bold">
                    <a href="index.php" class="text-blue-600">Beranda</a>
                    <a href="#jaminan-koslink" class="text-slate-500 hover:text-slate-900 transition">Tentang Kami</a>
                    <a href="#footer-bantuan" class="text-slate-500 hover:text-slate-900 transition">Bantuan</a>
                    <?php if($is_logged_in): ?>
                        <a href="penyewa/dashboard.php" class="text-blue-600 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-house-user"></i> Dashboard Penghuni
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <?php if(!$is_logged_in): ?>
                    <a href="auth/login.php" class="text-xs font-bold text-slate-700 hover:text-blue-600 px-3 py-2 transition">Masuk</a>
                    <a href="auth/register.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-xl shadow-sm transition">Daftar</a>
                <?php else: ?>
                    <a href="penyewa/dashboard.php" class="md:hidden bg-blue-50 text-blue-600 text-[11px] font-bold px-2.5 py-1.5 rounded-lg">Dashboard</a>
                    <div class="text-right hidden sm:block">
                        <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_user) ?></p>
                        <p class="text-[10px] text-emerald-600 font-bold"><?= $hunian_aktif ? 'Penyewa Aktif' : 'Pencari Kos' ?></p>
                    </div>
                    <a href="penyewa/dashboard.php" title="Dashboard Penghuni" class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 border border-blue-200 flex items-center justify-center text-xs font-bold">
                        <i class="fa-regular fa-user"></i>
                    </a>
                    <a href="auth/logout.php" title="Keluar" class="w-8 h-8 rounded-xl border border-slate-200 text-slate-500 hover:bg-rose-50 hover:text-rose-600 flex items-center justify-center text-xs transition">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6 flex-1">

        <?php if($hunian_aktif): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-2xl px-5 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
            <div class="flex items-center gap-3 text-xs">
                <div class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-key"></i>
                </div>
                <div>
                    <p class="font-extrabold text-slate-900">Anda Memiliki Hunian Aktif (<?= htmlspecialchars($hunian_aktif['no_kamar']) ?>)</p>
                    <p class="text-[11px] text-slate-600">Kelola pembayaran sewa bulanan, status escrow, dan kirim keluhan fasilitas.</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a href="penyewa/keluhan.php" class="bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-bold px-3.5 py-2 rounded-xl transition">Keluhan</a>
                <a href="penyewa/dashboard.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-xl transition shadow-xs">Dashboard Saya &rarr;</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- HERO BANNER -->
        <section class="relative rounded-3xl overflow-hidden bg-slate-900 shadow-md">
            <img src="https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1400&q=80" alt="Interior Kos" class="absolute inset-0 w-full h-full object-cover opacity-35">
            <div class="absolute inset-0 bg-gradient-to-b from-slate-900/60 via-slate-900/50 to-slate-900/80"></div>

            <div class="relative z-10 px-6 py-12 md:py-16 max-w-4xl mx-auto text-center space-y-4">
                <span class="inline-flex items-center gap-1.5 bg-white/15 backdrop-blur-md border border-white/20 text-white text-[10px] font-bold px-3.5 py-1 rounded-full">
                    <i class="fa-solid fa-shield-halved text-blue-400"></i> Platform Pencarian Kos Terverifikasi &amp; Resmi
                </span>

                <h1 class="text-3xl sm:text-4xl md:text-[42px] font-extrabold text-white tracking-tight leading-tight">
                    Temukan Kos Impianmu
                </h1>

                <p class="text-xs sm:text-sm text-slate-200 max-w-xl mx-auto leading-relaxed">
                    Solusi mudah mencari tempat tinggal nyaman dekat kampus dan kantor idaman dengan transaksi aman dan transparan.
                </p>

                <!-- Floating Search Box -->
                <div class="pt-4">
                    <div class="bg-white rounded-2xl p-2 shadow-2xl grid grid-cols-1 md:grid-cols-12 gap-2 text-left items-center">
                        <div class="md:col-span-5 px-3.5 py-2 flex items-center gap-3 border-b md:border-b-0 md:border-r border-slate-100">
                            <i class="fa-solid fa-location-dot text-blue-600 text-sm"></i>
                            <div class="w-full">
                                <label class="block text-[9px] font-extrabold text-slate-400 uppercase tracking-wider">LOKASI / KANTOR / KAMPUS</label>
                                <input type="text" id="hero-lokasi" oninput="terapkanFilter()" placeholder="Universitas Udayana, Jimbaran..." class="w-full text-xs font-bold text-slate-800 placeholder-slate-400 focus:outline-none mt-0.5">
                            </div>
                        </div>

                        <div class="md:col-span-3 px-3.5 py-2 flex items-center gap-3 border-b md:border-b-0 md:border-r border-slate-100">
                            <i class="fa-regular fa-calendar text-blue-600 text-sm"></i>
                            <div class="w-full">
                                <label class="block text-[9px] font-extrabold text-slate-400 uppercase tracking-wider">PERIODE SEWA</label>
                                <select id="hero-periode" onchange="terapkanFilter()" class="w-full text-xs font-bold text-slate-800 bg-transparent focus:outline-none mt-0.5 cursor-pointer">
                                    <option value="Bulanan">Bulanan (Mulai Segera)</option>
                                    <option value="Per 3 Bulan">Per 3 Bulan</option>
                                    <option value="Tahunan">Tahunan (Hemat)</option>
                                </select>
                            </div>
                        </div>

                        <div class="md:col-span-2 px-3.5 py-2 flex items-center gap-2.5">
                            <i class="fa-solid fa-user-group text-blue-600 text-xs"></i>
                            <div class="w-full">
                                <label class="block text-[9px] font-extrabold text-slate-400 uppercase tracking-wider">TIPE KOS</label>
                                <select id="hero-gender" onchange="sinkronGender(this.value)" class="w-full text-xs font-bold text-slate-800 bg-transparent focus:outline-none mt-0.5 cursor-pointer">
                                    <option value="semua">Semua Gender</option>
                                    <option value="putra">Putra</option>
                                    <option value="putri">Putri</option>
                                    <option value="campur">Campur</option>
                                </select>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <button type="button" onclick="terapkanFilter(); document.getElementById('katalog-kos').scrollIntoView({behavior:'smooth'});" class="w-full h-11 bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold rounded-xl transition flex items-center justify-center gap-2 shadow-sm">
                                <i class="fa-solid fa-magnifying-glass"></i> Cari Kos
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TREN PENCARIAN -->
        <div class="flex flex-wrap items-center gap-2 text-[11px] pt-1">
            <span class="font-bold text-slate-400 flex items-center gap-1 mr-1">
                <i class="fa-solid fa-arrow-trend-up text-blue-600"></i> Tren Pencarian:
            </span>
            <button type="button" onclick="quickCari('Jimbaran')" class="px-3 py-1 rounded-full bg-white border border-slate-200 hover:border-blue-400 text-slate-600 font-semibold transition shadow-2xs">Univ. Udayana Kampus Jimbaran</button>
            <button type="button" onclick="quickCari('Putri')" class="px-3 py-1 rounded-full bg-white border border-slate-200 hover:border-blue-400 text-slate-600 font-semibold transition shadow-2xs">Kos Putri Jimbaran</button>
            <button type="button" onclick="quickCari('WiFi')" class="px-3 py-1 rounded-full bg-white border border-slate-200 hover:border-blue-400 text-slate-600 font-semibold transition shadow-2xs">Kos Bebas 24 Jam &amp; WiFi</button>
            <button type="button" onclick="quickCari('AC')" class="px-3 py-1 rounded-full bg-white border border-slate-200 hover:border-blue-400 text-slate-600 font-semibold transition shadow-2xs">Kos Kamar Mandi Dalam &amp; AC</button>
            <button type="button" onclick="quickHarga(1000000)" class="px-3 py-1 rounded-full bg-white border border-slate-200 hover:border-blue-400 text-slate-600 font-semibold transition shadow-2xs">Kos Murah &lt; 1 Juta</button>
        </div>

        <!-- FILTER BAR -->
        <div id="katalog-kos" class="space-y-3 pt-1">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button type="button" onclick="bukaModalFilter()" class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-4 py-2.5 rounded-xl shadow-xs transition">
                        <i class="fa-solid fa-sliders"></i> Semua Filter <span id="badge-filter-count" class="bg-white text-blue-600 text-[10px] px-1.5 py-0.2 rounded-full font-extrabold hidden">0</span>
                    </button>
                </div>

                <div class="flex items-center gap-2 text-xs">
                    <span class="text-slate-400 font-semibold">Urutkan:</span>
                    <select id="sort-kos" onchange="urutkanKos()" class="bg-white border border-slate-200 text-slate-800 font-bold px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer shadow-2xs">
                        <option value="terbaru">Rekomendasi</option>
                        <option value="termurah">Harga Terendah</option>
                        <option value="termahal">Harga Tertinggi</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 text-xs pt-1">
                <p class="text-slate-600">
                    Menampilkan <strong class="text-slate-900" id="jumlah-tampil"><?= count($daftar_kos) ?></strong> kos tersedia &bull; <span class="text-emerald-600 font-bold"><i class="fa-solid fa-circle-check"></i> 100% Siap Huni &amp; Terverifikasi</span>
                </p>
                <button type="button" onclick="resetSemuaFilter()" class="text-blue-600 hover:underline font-bold text-xs">Reset Semua</button>
            </div>
        </div>

        <!-- GRID 4 KOLOM KATALOG KOS -->
        <?php if(count($daftar_kos) === 0): ?>
        <div class="bg-white border border-slate-200/80 rounded-3xl p-14 text-center shadow-sm">
            <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-3">
                <i class="fa-solid fa-house-circle-check"></i>
            </div>
            <h3 class="text-base font-extrabold text-slate-900">Belum Ada Unit Kos yang Ditambahkan Mitra</h3>
            <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto leading-relaxed">
                Kamar kos yang didaftarkan oleh Mitra Pemilik melalui panel mitra akan otomatis tampil di katalog ini.
            </p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5" id="grid-katalog-kos">
            <?php foreach($daftar_kos as $idx => $kos): 
                $id_kamar   = (int)$kos[$pk_kamar];
                
                // --- PERBAIKAN KOLOM HARGA: Menangkap semua kemungkinan nama kolom harga ---
                $harga_num  = (int)($kos['harga_bulanan'] ?? $kos['harga_bulan'] ?? $kos['harga_sewa'] ?? $kos['harga'] ?? 0);
                
                $nama_unit  = $kos[$col_no_kamar] ?? ('Unit #' . $id_kamar);
                $tipe_unit  = $kos[$col_tipe_kamar] ?? 'Standard';
                $nama_prop  = $kos['nama_kos_prop'] ?? 'Properti KosLink';
                $alamat     = $kos['alamat_prop'] ?: 'Jimbaran, Kuta Selatan';
                $gender     = ucfirst(strtolower(trim($kos['jenis_kos_prop'] ?: 'Campur')));
                $fasilitas  = $col_fasilitas !== '' ? ($kos[$col_fasilitas] ?: 'AC, WiFi, K. Mandi Dalam') : 'AC, WiFi, K. Mandi Dalam';
                $foto_url   = ($col_foto !== '' && !empty($kos[$col_foto])) ? 'uploads/' . $kos[$col_foto] : 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=600&q=80';
                $tersedia   = $kos['is_tersedia'];

                $badge_gender_cls = 'bg-slate-800/80 text-white';
                if (stripos($gender, 'putri') !== false) $badge_gender_cls = 'bg-rose-500 text-white';
                elseif (stripos($gender, 'putra') !== false) $badge_gender_cls = 'bg-blue-600 text-white';
            ?>
            <div class="item-kos bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition flex flex-col justify-between"
                 data-index="<?= $idx ?>"
                 data-harga="<?= $harga_num ?>"
                 data-gender="<?= htmlspecialchars(strtolower($gender)) ?>"
                 data-status="<?= $tersedia ? 'tersedia' : 'terisi' ?>"
                 data-search="<?= htmlspecialchars(strtolower($nama_prop . ' ' . $nama_unit . ' ' . $tipe_unit . ' ' . $alamat . ' ' . $fasilitas . ' ' . $gender)) ?>">

                <div>
                    <div class="relative h-44 bg-slate-100">
                        <a href="detail_kamar.php?id=<?= $id_kamar ?>">
                            <img src="<?= htmlspecialchars($foto_url) ?>" onerror="this.src='https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=600&q=80'" alt="<?= htmlspecialchars($nama_prop) ?>" class="w-full h-full object-cover">
                        </a>

                        <div class="absolute top-2.5 left-2.5 flex items-center gap-1.5">
                            <span class="bg-white/95 text-blue-700 text-[10px] font-extrabold px-2 py-0.5 rounded-md shadow-xs">
                                Terverifikasi
                            </span>
                            <span class="<?= $badge_gender_cls ?> text-[10px] font-extrabold px-2 py-0.5 rounded-md shadow-xs">
                                <?= htmlspecialchars($gender) ?>
                            </span>
                        </div>

                        <button type="button" onclick="this.querySelector('i').classList.toggle('fa-solid'); this.querySelector('i').classList.toggle('fa-regular'); this.querySelector('i').classList.toggle('text-rose-500');" class="absolute top-2.5 right-2.5 w-7 h-7 rounded-full bg-white/90 text-slate-600 flex items-center justify-center text-xs shadow-xs hover:bg-white transition">
                            <i class="fa-regular fa-heart"></i>
                        </button>

                        <div class="absolute bottom-2.5 left-2.5">
                            <span class="bg-slate-900/80 backdrop-blur-xs text-white text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1">
                                <i class="fa-solid fa-star text-amber-400"></i> 4.8 (24)
                            </span>
                        </div>
                    </div>

                    <div class="p-4 space-y-2">
                        <p class="text-[10px] font-bold text-blue-600 truncate">
                            <i class="fa-solid fa-location-dot mr-1"></i>&plusmn; 300m dari Kampus Univ. Udayana
                        </p>
                        <div>
                            <a href="detail_kamar.php?id=<?= $id_kamar ?>" class="text-sm font-extrabold text-slate-900 hover:text-blue-600 truncate block" title="<?= htmlspecialchars($nama_prop . ' - ' . $nama_unit) ?>">
                                <?= htmlspecialchars($nama_prop) ?> &bull; <?= htmlspecialchars($nama_unit) ?>
                            </a>
                            <p class="text-[11px] text-slate-400 truncate mt-0.5"><?= htmlspecialchars($alamat) ?></p>
                        </div>

                        <div class="flex flex-wrap gap-1 pt-0.5">
                            <?php foreach(array_slice(explode(',', $fasilitas), 0, 3) as $f): if(trim($f) === '') continue; ?>
                                <span class="bg-slate-50 border border-slate-200/80 text-slate-600 text-[10px] font-semibold px-2 py-0.5 rounded-md">
                                    <?= htmlspecialchars(trim($f)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-[9px] font-bold text-slate-400 uppercase">Mulai dari</p>
                        <p class="text-sm font-extrabold text-slate-900">
                            Rp <?= number_format($harga_num, 0, ',', '.') ?><span class="text-[10px] font-medium text-slate-400">/bln</span>
                        </p>
                    </div>
                    <a href="detail_kamar.php?id=<?= $id_kamar ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-lg transition shadow-2xs">
                        Detail
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- BANNER PETA INTERAKTIF -->
        <section class="bg-gradient-to-r from-blue-50/90 to-indigo-50/70 border border-blue-200/70 rounded-3xl p-6 md:p-8 grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
            <div class="lg:col-span-7 space-y-3">
                <p class="text-[11px] font-extrabold text-blue-600 flex items-center gap-1.5">
                    <i class="fa-solid fa-location-crosshairs"></i> Cari Berdasarkan Radius &amp; Titik Lokasi
                </p>
                <h2 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">
                    Temukan Kos Dekat Kantor, Kampus &amp; Stasiun
                </h2>
                <p class="text-xs text-slate-600 leading-relaxed max-w-xl">
                    Cari hunian dalam radius jalan kaki atau berkendara dari kantor, pusat bisnis (CBD), stasiun transit, maupun universitas favorit Anda.
                </p>
                <div class="pt-1">
                    <button type="button" onclick="bukaModalPeta()" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-sm transition">
                        <i class="fa-regular fa-map"></i> Buka Mode Peta Interaktif
                    </button>
                </div>
            </div>

            <div class="lg:col-span-5">
                <div onclick="bukaModalPeta()" class="cursor-pointer relative h-40 rounded-2xl overflow-hidden border border-blue-200 bg-sky-100 shadow-inner group">
                    <div id="mini-map-preview" class="w-full h-full z-0"></div>
                    <div class="absolute inset-0 bg-slate-900/10 group-hover:bg-slate-900/5 transition z-10 flex items-center justify-center">
                        <span class="bg-white/95 text-blue-600 text-xs font-extrabold px-3.5 py-1.5 rounded-full shadow-md">
                            <i class="fa-solid fa-expand mr-1"></i> Klik untuk Perbesar Peta
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- 3 KARTU JAMINAN KEPERCAYAAN -->
        <section id="jaminan-koslink" class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-2">
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm space-y-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-base">
                    <i class="fa-solid fa-shield-check"></i>
                </div>
                <h3 class="text-sm font-extrabold text-slate-900">Survei 100% Asli</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Tim KosLink memverifikasi langsung kondisi fisik kamar, ketersediaan fasilitas, serta keakuratan harga setiap unit.
                </p>
            </div>

            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm space-y-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-base">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h3 class="text-sm font-extrabold text-slate-900">Pembayaran Rekber Aman</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Uang sewa disimpan dalam rekening bersama terenkripsi dan baru diteruskan ke pemilik setelah serah terima kunci kamar.
                </p>
            </div>

            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm space-y-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-base">
                    <i class="fa-solid fa-headset"></i>
                </div>
                <h3 class="text-sm font-extrabold text-slate-900">Dukungan Penghuni 24/7</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Bantuan darurat seputar fasilitas, kendala teknis air atau listrik, dan konsultasi kontrak sewa setiap hari tanpa henti.
                </p>
            </div>
        </section>

    </main>

    <!-- FOOTER -->
    <footer id="footer-bantuan" class="bg-white border-t border-slate-200/80 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 grid grid-cols-1 md:grid-cols-12 gap-8">
            <div class="md:col-span-6 space-y-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white">
                        <i class="fa-solid fa-building text-sm"></i>
                    </div>
                    <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
                </div>
                <p class="text-xs text-slate-500 max-w-sm leading-relaxed">
                    Platform pencarian dan penyewaan kos modern, transparan, dan terpercaya untuk mahasiswa dan profesional muda di seluruh Indonesia.
                </p>
            </div>

            <div class="md:col-span-3 space-y-2.5 text-xs">
                <p class="font-extrabold text-slate-900">Navigasi Cepat</p>
                <ul class="space-y-2 text-slate-500 font-medium">
                    <li><a href="index.php" class="hover:text-blue-600">Cari Kos</a></li>
                    <li><a href="#jaminan-koslink" class="hover:text-blue-600">Tentang KosLink</a></li>
                    <li><a href="<?= $is_logged_in ? 'penyewa/dashboard.php' : 'auth/login.php' ?>" class="hover:text-blue-600">Dashboard Penghuni</a></li>
                </ul>
            </div>

            <div class="md:col-span-3 space-y-2.5 text-xs">
                <p class="font-extrabold text-slate-900">Legalitas &amp; Dukungan</p>
                <ul class="space-y-2 text-slate-500 font-medium">
                    <li><a href="#jaminan-koslink" class="hover:text-blue-600">Syarat &amp; Ketentuan</a></li>
                    <li><a href="#jaminan-koslink" class="hover:text-blue-600">Perlindungan Rekber</a></li>
                    <li><a href="https://wa.me/6281234567890" target="_blank" class="hover:text-blue-600">Hubungi CS</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-slate-100 py-4 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between text-[11px] text-slate-400">
            <span>&copy; <?= date('Y') ?> KosLink Indonesia. Hak cipta dilindungi undang-undang.</span>
            <span>Dibuat untuk kemudahan hunian nusantara.</span>
        </div>
    </footer>

    <!-- MODAL FILTER PENCARIAN KOS -->
    <div id="modal-filter" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl max-w-2xl w-full overflow-hidden shadow-2xl border border-slate-100 my-8">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-extrabold text-slate-900">Filter Pencarian Kos</h3>
                        <p class="text-[11px] text-slate-400">Sesuaikan fasilitas, harga, dan kriteria kos idamanmu</p>
                    </div>
                </div>
                <button type="button" onclick="tutupModal('modal-filter')" class="w-8 h-8 rounded-full bg-slate-50 text-slate-500 hover:text-slate-800 flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto text-xs">
                
                <div class="space-y-2.5">
                    <div class="flex items-center justify-between">
                        <label class="font-extrabold text-slate-900">Tipe Kos / Gender Penghuni</label>
                        <span class="text-[10px] text-slate-400 font-semibold">Pilih salah satu</span>
                    </div>
                    <div class="grid grid-cols-4 gap-2 bg-slate-50 p-1.5 rounded-2xl border border-slate-200/70">
                        <button type="button" onclick="pilihModalGender('semua', this)" class="btn-gender py-2 rounded-xl font-bold text-center transition bg-blue-600 text-white shadow-xs" data-val="semua">Semua</button>
                        <button type="button" onclick="pilihModalGender('putra', this)" class="btn-gender py-2 rounded-xl font-bold text-center transition text-slate-600 hover:bg-white" data-val="putra">Kos Putra</button>
                        <button type="button" onclick="pilihModalGender('putri', this)" class="btn-gender py-2 rounded-xl font-bold text-center transition text-slate-600 hover:bg-white" data-val="putri">Kos Putri</button>
                        <button type="button" onclick="pilihModalGender('campur', this)" class="btn-gender py-2 rounded-xl font-bold text-center transition text-slate-600 hover:bg-white" data-val="campur">Campur</button>
                    </div>
                </div>

                <div class="space-y-2.5">
                    <label class="font-extrabold text-slate-900 block">Periode Pembayaran Sewa</label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="pilihPeriode(this)" class="btn-periode px-4 py-2 rounded-xl font-bold border border-blue-300 bg-blue-50 text-blue-700 transition">✓ Bulanan</button>
                        <button type="button" onclick="pilihPeriode(this)" class="btn-periode px-4 py-2 rounded-xl font-bold border border-slate-200 bg-slate-50 text-slate-600 hover:bg-white transition">Tahunan</button>
                        <button type="button" onclick="pilihPeriode(this)" class="btn-periode px-4 py-2 rounded-xl font-bold border border-slate-200 bg-slate-50 text-slate-600 hover:bg-white transition">Harian</button>
                        <button type="button" onclick="pilihPeriode(this)" class="btn-periode px-4 py-2 rounded-xl font-bold border border-slate-200 bg-slate-50 text-slate-600 hover:bg-white transition">Per 3 Bulan</button>
                    </div>
                </div>

                <div class="space-y-3 bg-slate-50/70 p-4 rounded-2xl border border-slate-200/70">
                    <div class="flex items-center justify-between">
                        <div>
                            <label class="font-extrabold text-slate-900 block">Rentang Harga Sewa (per bulan)</label>
                            <p class="text-[10px] text-slate-400 mt-0.5">Rata-rata sewa di lokasi ini Rp 1.650.000</p>
                        </div>
                        <span class="font-extrabold text-blue-600" id="label-range-str">Rp 500 Rb - Rp 5.0 Jt</span>
                    </div>

                    <div class="flex items-end gap-1 h-12 pt-2 px-1">
                        <?php 
                        $dummy_bars = [20, 30, 45, 60, 80, 95, 100, 85, 75, 90, 80, 70, 85, 90, 95, 80, 60, 50, 40, 25, 20, 15, 10, 5];
                        foreach($dummy_bars as $bh): 
                        ?>
                            <div class="flex-1 bg-blue-200 rounded-t-xs hover:bg-blue-500 transition" style="height: <?= $bh ?>%;"></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="space-y-1">
                        <input type="range" id="slider-harga" min="300000" max="5000000" step="100000" value="5000000" oninput="updateRangeHarga(this.value)" class="w-full accent-blue-600 cursor-pointer">
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-1">
                        <div class="bg-white p-3 rounded-xl border border-slate-200">
                            <span class="text-[10px] text-slate-400 uppercase font-bold">Harga Minimum</span>
                            <p class="font-extrabold text-slate-900 text-sm mt-0.5">Rp 300.000</p>
                        </div>
                        <div class="bg-white p-3 rounded-xl border border-slate-200">
                            <span class="text-[10px] text-slate-400 uppercase font-bold">Harga Maksimum</span>
                            <p class="font-extrabold text-slate-900 text-sm mt-0.5" id="val-max-text">Rp 5.000.000</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <span class="text-slate-400 font-bold">Pilihan Cepat:</span>
                        <button type="button" onclick="setHargaCepat(1000000)" class="px-2.5 py-1 rounded-lg bg-white border border-slate-200 font-bold hover:border-blue-400">&lt; Rp 1 Jt</button>
                        <button type="button" onclick="setHargaCepat(2000000)" class="px-2.5 py-1 rounded-lg bg-white border border-slate-200 font-bold hover:border-blue-400">Rp 1 - 2 Jt</button>
                        <button type="button" onclick="setHargaCepat(5000000)" class="px-2.5 py-1 rounded-lg bg-white border border-slate-200 font-bold hover:border-blue-400">&gt; Rp 2 Jt</button>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="font-extrabold text-slate-900">Fasilitas Kamar &amp; Bersama</label>
                        <span class="text-[10px] text-blue-600 font-bold">Pilih fasilitas penting</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="ac" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-snowflake text-blue-500 mr-1"></i> AC Kamar</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="wifi" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-wifi text-blue-500 mr-1"></i> WiFi</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="mandi" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-bath text-blue-500 mr-1"></i> KM Dalam</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="springbed" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-bed text-blue-500 mr-1"></i> Kasur Springbed</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="dapur" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-kitchen-set text-blue-500 mr-1"></i> Dapur Bersama</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="parkir" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-car text-blue-500 mr-1"></i> Parkir Mobil</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="motor" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-motorcycle text-blue-500 mr-1"></i> Parkir Motor</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="tv" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-tv text-blue-500 mr-1"></i> TV Kamar</span>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300">
                            <input type="checkbox" name="fasilitas-chk" value="lemari" class="w-4 h-4 accent-blue-600 rounded">
                            <span class="font-bold text-slate-800"><i class="fa-solid fa-box-archive text-blue-500 mr-1"></i> Lemari &amp; Meja</span>
                        </label>
                    </div>
                </div>

            </div>

            <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between bg-slate-50/50">
                <button type="button" onclick="resetSemuaFilter(); tutupModal('modal-filter');" class="text-slate-500 hover:text-slate-800 font-extrabold text-xs">
                    Hapus Semua Filter
                </button>
                <button type="button" onclick="terapkanFilterModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-6 py-2.5 rounded-xl shadow-md transition">
                    Terapkan Filter <span id="btn-count-kos">(<?= count($daftar_kos) ?> Kos)</span>
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL PETA INTERAKTIF -->
    <div id="modal-peta" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-3xl max-w-4xl w-full overflow-hidden shadow-2xl border border-slate-100">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Peta Interaktif Lokasi Kos &amp; Kampus</h3>
                    <p class="text-[11px] text-slate-400">Area Bukit Jimbaran, Kampus Universitas Udayana &amp; Kuta Selatan</p>
                </div>
                <button onclick="tutupModal('modal-peta')" class="w-8 h-8 rounded-full bg-slate-50 text-slate-500 hover:text-slate-800 flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="full-map" class="w-full h-[420px]"></div>
        </div>
    </div>

    <script>
        let modalSelectedGender = 'semua';
        let maxHargaFilter = 5000000;

        function sinkronGender(val) {
            modalSelectedGender = val;
            document.getElementById('hero-gender').value = val;
            terapkanFilter();
        }

        function pilihModalGender(val, btn) {
            modalSelectedGender = val;
            document.querySelectorAll('.btn-gender').forEach(b => {
                b.className = "btn-gender py-2 rounded-xl font-bold text-center transition text-slate-600 hover:bg-white";
            });
            btn.className = "btn-gender py-2 rounded-xl font-bold text-center transition bg-blue-600 text-white shadow-xs";
        }

        function pilihPeriode(btn) {
            document.querySelectorAll('.btn-periode').forEach(b => {
                b.className = "btn-periode px-4 py-2 rounded-xl font-bold border border-slate-200 bg-slate-50 text-slate-600 hover:bg-white transition";
                b.innerText = b.innerText.replace('✓ ', '');
            });
            btn.className = "btn-periode px-4 py-2 rounded-xl font-bold border border-blue-300 bg-blue-50 text-blue-700 transition";
            btn.innerText = '✓ ' + btn.innerText;
        }

        function updateRangeHarga(val) {
            maxHargaFilter = parseInt(val);
            const juta = (maxHargaFilter / 1000000).toFixed(1);
            document.getElementById('label-range-str').innerText = `Rp 500 Rb - Rp ${juta} Jt`;
            document.getElementById('val-max-text').innerText = 'Rp ' + maxHargaFilter.toLocaleString('id-ID');
        }

        function setHargaCepat(nominal) {
            document.getElementById('slider-harga').value = nominal;
            updateRangeHarga(nominal);
        }

        function bukaModalFilter() {
            bukaModal('modal-filter');
        }

        function terapkanFilterModal() {
            tutupModal('modal-filter');
            terapkanFilter();
            document.getElementById('katalog-kos').scrollIntoView({ behavior: 'smooth' });
        }

        function quickCari(keyword) {
            document.getElementById('hero-lokasi').value = keyword;
            terapkanFilter();
            document.getElementById('katalog-kos').scrollIntoView({ behavior: 'smooth' });
        }

        function quickHarga(maxHarga) {
            maxHargaFilter = maxHarga;
            document.getElementById('slider-harga').value = maxHarga;
            updateRangeHarga(maxHarga);
            terapkanFilter();
            document.getElementById('katalog-kos').scrollIntoView({ behavior: 'smooth' });
        }

        function terapkanFilter() {
            const qLok   = document.getElementById('hero-lokasi').value.toLowerCase().trim();
            const gender = modalSelectedGender;
            const harga  = maxHargaFilter;

            let selectedFas = [];
            document.querySelectorAll('input[name="fasilitas-chk"]:checked').forEach(chk => {
                selectedFas.push(chk.value);
            });

            let tampil = 0;
            document.querySelectorAll('.item-kos').forEach(card => {
                const cSearch = card.getAttribute('data-search') || '';
                const cGender = card.getAttribute('data-gender') || '';
                const cHarga  = parseInt(card.getAttribute('data-harga')) || 0;

                const matchLok    = (!qLok || cSearch.includes(qLok));
                const matchGender = (gender === 'semua' || cGender.includes(gender));
                const matchHarga  = (cHarga <= harga);
                const matchStatus = true; 

                let matchFas = true;
                selectedFas.forEach(f => {
                    if (!cSearch.includes(f)) matchFas = false;
                });

                if (matchLok && matchGender && matchHarga && matchStatus && matchFas) {
                    card.style.display = '';
                    tampil++;
                } else {
                    card.style.display = 'none';
                }
            });

            const elJml = document.getElementById('jumlah-tampil');
            if (elJml) elJml.innerText = tampil;

            const btnCount = document.getElementById('btn-count-kos');
            if (btnCount) btnCount.innerText = `(${tampil} Kos)`;

            let activeCount = 0;
            if (gender !== 'semua') activeCount++;
            if (harga < 5000000) activeCount++;
            if (selectedFas.length > 0) activeCount += selectedFas.length;
            
            const badgeF = document.getElementById('badge-filter-count');
            if (badgeF) {
                if (activeCount > 0) {
                    badgeF.innerText = activeCount;
                    badgeF.classList.remove('hidden');
                } else {
                    badgeF.classList.add('hidden');
                }
            }
        }

        function urutkanKos() {
            const mode = document.getElementById('sort-kos').value;
            const grid = document.getElementById('grid-katalog-kos');
            if (!grid) return;

            const items = Array.from(grid.querySelectorAll('.item-kos'));
            items.sort((a, b) => {
                const ha = parseInt(a.getAttribute('data-harga')) || 0;
                const hb = parseInt(b.getAttribute('data-harga')) || 0;
                const ia = parseInt(a.getAttribute('data-index')) || 0;
                const ib = parseInt(b.getAttribute('data-index')) || 0;

                if (mode === 'termurah') return ha - hb;
                if (mode === 'termahal') return hb - ha;
                return ia - ib;
            });

            items.forEach(item => grid.appendChild(item));
        }

        function resetSemuaFilter() {
            document.getElementById('hero-lokasi').value = '';
            modalSelectedGender = 'semua';
            maxHargaFilter = 5000000;
            document.getElementById('slider-harga').value = 5000000;
            updateRangeHarga(5000000);
            document.getElementById('sort-kos').value = 'terbaru';
            document.querySelectorAll('input[name="fasilitas-chk"]').forEach(chk => chk.checked = false);
            
            document.querySelectorAll('.btn-gender').forEach(b => {
                if(b.getAttribute('data-val') === 'semua') {
                    b.className = "btn-gender py-2 rounded-xl font-bold text-center transition bg-blue-600 text-white shadow-xs";
                } else {
                    b.className = "btn-gender py-2 rounded-xl font-bold text-center transition text-slate-600 hover:bg-white";
                }
            });

            urutkanKos();
            terapkanFilter();
        }

        function bukaModal(id) {
            const m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function tutupModal(id) {
            const m = document.getElementById(id);
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        const koordinatUnud = [-8.7985, 115.1725];
        if (document.getElementById('mini-map-preview')) {
            const miniMap = L.map('mini-map-preview', { zoomControl: false, dragging: false, scrollWheelZoom: false }).setView(koordinatUnud, 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(miniMap);
            L.marker(koordinatUnud).addTo(miniMap);
        }

        let fullMapInit = false;
        function bukaModalPeta() {
            bukaModal('modal-peta');
            if (!fullMapInit) {
                setTimeout(() => {
                    const fMap = L.map('full-map').setView(koordinatUnud, 14);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(fMap);
                    L.marker(koordinatUnud).addTo(fMap).bindPopup('<b>Area Kampus Unud &amp; Bukit Jimbaran</b><br>Pusat Hunian Mahasiswa KosLink').openPopup();
                    fullMapInit = true;
                }, 200);
            }
        }

        terapkanFilter();
    </script>
</body>
</html>