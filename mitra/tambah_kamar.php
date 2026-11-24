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
    return $candidates[0];
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

// 1. PASTIKAN TABEL PROPERTI KOS TERSEDIA (1 AKUN = 1 PROPERTI)
$tbl_properti = resolveExistingTable($conn, ['properti_kos', 'Properti_Kos', 'properti', 'Properti']);
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `$tbl_properti` (
    `id_properti` INT AUTO_INCREMENT PRIMARY KEY,
    `id_mitra` INT NOT NULL DEFAULT 0,
    `nama_kos` VARCHAR(150) NOT NULL,
    `alamat` TEXT,
    `jenis_kos` VARCHAR(50) DEFAULT 'Campur',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cols_prop      = getTableColumns($conn, $tbl_properti);
$pk_prop        = in_array('id_properti', $cols_prop, true) ? 'id_properti' : (in_array('id_kos', $cols_prop, true) ? 'id_kos' : 'id');
$col_nama_kos   = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : (in_array('nama_properti', $cols_prop, true) ? 'nama_properti' : 'nama_kos');
$col_mitra_prop = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : (in_array('id_user', $cols_prop, true) ? 'id_user' : 'id_pemilik');

// 2. AMBIL 1 PROPERTI MILIK MITRA (AUTO-CREATE JIKA BELUM PUNYA)
$properti_aktif = null;
if ($id_mitra > 0 && !empty($cols_prop)) {
    $q_cek_prop = @mysqli_query($conn, "SELECT * FROM `$tbl_properti` WHERE `$col_mitra_prop` = $id_mitra ORDER BY `$pk_prop` ASC LIMIT 1");
    if ($q_cek_prop && ($rp = mysqli_fetch_assoc($q_cek_prop))) {
        $properti_aktif = $rp;
    } else {
        $nama_kos_default = mysqli_real_escape_string($conn, 'Kos ' . $nama_mitra);
        $alamat_default   = mysqli_real_escape_string($conn, 'Lokasi Properti Mitra');
        $col_alamat_kos   = in_array('alamat', $cols_prop, true) ? 'alamat' : (in_array('lokasi', $cols_prop, true) ? 'lokasi' : '');

        $prop_fields = ["`$col_mitra_prop`", "`$col_nama_kos`"];
        $prop_values = [$id_mitra, "'$nama_kos_default'"];
        if ($col_alamat_kos !== '') {
            $prop_fields[] = "`$col_alamat_kos`";
            $prop_values[] = "'$alamat_default'";
        }

        $sql_auto = "INSERT INTO `$tbl_properti` (" . implode(', ', $prop_fields) . ") VALUES (" . implode(', ', $prop_values) . ")";
        if (@mysqli_query($conn, $sql_auto)) {
            $q_recheck = @mysqli_query($conn, "SELECT * FROM `$tbl_properti` WHERE `$col_mitra_prop` = $id_mitra ORDER BY `$pk_prop` ASC LIMIT 1");
            if ($q_recheck && ($rp2 = mysqli_fetch_assoc($q_recheck))) {
                $properti_aktif = $rp2;
            }
        }
    }
}

$selected_properti_id = $properti_aktif ? (int)($properti_aktif[$pk_prop] ?? 0) : 0;
$nama_properti_aktif = ($properti_aktif && isset($properti_aktif[$col_nama_kos]) && $properti_aktif[$col_nama_kos] !== '')
    ? (string)$properti_aktif[$col_nama_kos]
    : 'Properti Mitra';

// 3. PASTIKAN TABEL KAMAR & FOTO KAMAR TERSEDIA
$tbl_kamar      = resolveExistingTable($conn, ['kamar', 'Kamar']);
$tbl_foto_kamar = resolveExistingTable($conn, ['foto_kamar', 'Foto_Kamar']);

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `$tbl_kamar` (
    `id_kamar` INT AUTO_INCREMENT PRIMARY KEY,
    `id_properti` INT NOT NULL DEFAULT 0,
    `nama_kamar` VARCHAR(100) NOT NULL,
    `tipe_kamar` VARCHAR(80) DEFAULT 'Standard',
    `harga_bulan` BIGINT NOT NULL DEFAULT 0,
    `sisa_kamar` INT DEFAULT 1,
    `fasilitas` TEXT,
    `deskripsi` TEXT,
    `foto_kamar` VARCHAR(255) DEFAULT '',
    `status_kamar` VARCHAR(30) DEFAULT 'Tersedia',
    `status_validasi` VARCHAR(30) DEFAULT 'pending',
    `catatan_validasi` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `$tbl_foto_kamar` (
    `id_foto` INT AUTO_INCREMENT PRIMARY KEY,
    `id_kamar` INT NOT NULL,
    `nama_file` VARCHAR(255) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cols_kamar = getTableColumns($conn, $tbl_kamar);

// Pastikan kolom status_validasi & catatan_validasi ada di tabel kamar
if (!in_array('status_validasi', $cols_kamar, true)) {
    @mysqli_query($conn, "ALTER TABLE `$tbl_kamar` ADD COLUMN `status_validasi` VARCHAR(30) DEFAULT 'pending'");
    $cols_kamar[] = 'status_validasi';
}
if (!in_array('catatan_validasi', $cols_kamar, true)) {
    @mysqli_query($conn, "ALTER TABLE `$tbl_kamar` ADD COLUMN `catatan_validasi` TEXT NULL");
    $cols_kamar[] = 'catatan_validasi';
}

$col_status_kamar = '';
if (in_array('status_kamar', $cols_kamar, true)) {
    $col_status_kamar = 'status_kamar';
} elseif (in_array('status', $cols_kamar, true)) {
    $col_status_kamar = 'status';
} else {
    @mysqli_query($conn, "ALTER TABLE `$tbl_kamar` ADD COLUMN `status_kamar` VARCHAR(30) DEFAULT 'Tersedia'");
    $col_status_kamar = 'status_kamar';
    $cols_kamar[] = 'status_kamar';
}

$col_foto = '';
if (in_array('foto_kamar', $cols_kamar, true)) {
    $col_foto = 'foto_kamar';
} elseif (in_array('foto', $cols_kamar, true)) {
    $col_foto = 'foto';
} elseif (in_array('gambar', $cols_kamar, true)) {
    $col_foto = 'gambar';
} else {
    @mysqli_query($conn, "ALTER TABLE `$tbl_kamar` ADD COLUMN `foto_kamar` VARCHAR(255) DEFAULT ''");
    $col_foto = 'foto_kamar';
    $cols_kamar[] = 'foto_kamar';
}

$fk_kamar        = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : (in_array('id_kos', $cols_kamar, true) ? 'id_kos' : 'id_properti');
$col_no_kamar    = in_array('nama_kamar', $cols_kamar, true) ? 'nama_kamar' : (in_array('nomor_kamar', $cols_kamar, true) ? 'nomor_kamar' : 'nama_kamar');
$col_tipe_kamar  = in_array('tipe_kamar', $cols_kamar, true) ? 'tipe_kamar' : '';
$col_harga_kamar = in_array('harga_bulan', $cols_kamar, true) ? 'harga_bulan' : (in_array('harga_bulanan', $cols_kamar, true) ? 'harga_bulanan' : (in_array('harga_sewa', $cols_kamar, true) ? 'harga_sewa' : 'harga_bulan'));
$col_jml_unit    = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : (in_array('jumlah_unit', $cols_kamar, true) ? 'jumlah_unit' : '');
$col_fasilitas   = in_array('fasilitas', $cols_kamar, true) ? 'fasilitas' : '';
$col_deskripsi   = in_array('deskripsi', $cols_kamar, true) ? 'deskripsi' : '';

$notif_error = '';

// 4. PROSES SIMPAN KAMAR BARU + MULTI FOTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_kamar'])) {
    $nama_tipe_kamar = trim($_POST['nama_tipe_kamar'] ?? '');
    $jumlah_unit     = (int)($_POST['jumlah_unit'] ?? 1);
    $harga_bulanan   = (int)preg_replace('/[^0-9]/', '', $_POST['harga_bulanan'] ?? '0');

    $is_draft     = (isset($_POST['mode_simpan']) && $_POST['mode_simpan'] === 'draft');
    $status_kamar = $is_draft ? 'Draft' : 'Tersedia';
    if ($is_draft) $jumlah_unit = 0;

    // Kamar baru wajib masuk antrean validasi Super Admin ('pending')
    $status_validasi = 'pending';

    $tipe_kasur  = trim($_POST['tipe_kasur'] ?? 'Single');
    $kamar_mandi = trim($_POST['kamar_mandi'] ?? '');
    $panjang     = (float)($_POST['panjang'] ?? 0);
    $lebar       = (float)($_POST['lebar'] ?? 0);
    $luas        = $panjang * $lebar;
    $kapasitas   = trim($_POST['kapasitas'] ?? '');

    $fasilitas_arr = $_POST['fasilitas'] ?? [];
    if ($kamar_mandi !== '') array_unshift($fasilitas_arr, $kamar_mandi);
    if ($tipe_kasur !== '') array_unshift($fasilitas_arr, $tipe_kasur);
    $fasilitas_str = implode(', ', array_unique($fasilitas_arr));

    $deskripsi_kamar = trim($_POST['deskripsi'] ?? '');
    $deskripsi_full  = "Luas {$luas} m² ({$panjang}x{$lebar}m), {$kapasitas}. \n\n" . $deskripsi_kamar;

    // Proses Multi-Upload Foto (cek dari slot_foto[] maupun foto_kamar[])
    $uploaded_files = [];
    $upload_dir = '../uploads/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    $sumber_files = null;
    if (isset($_FILES['slot_foto']) && !empty($_FILES['slot_foto']['name'][0])) {
        $sumber_files = $_FILES['slot_foto'];
    } elseif (isset($_FILES['foto_kamar']) && !empty($_FILES['foto_kamar']['name'][0])) {
        $sumber_files = $_FILES['foto_kamar'];
    }

    if ($sumber_files !== null && is_array($sumber_files['name'])) {
        $total_files = min(count($sumber_files['name']), 5);
        for ($i = 0; $i < $total_files; $i++) {
            if (isset($sumber_files['error'][$i]) && $sumber_files['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name  = $sumber_files['tmp_name'][$i];
                $orig_name = $sumber_files['name'][$i];
                $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $new_name = 'kamar_' . time() . '_' . $i . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                        $uploaded_files[] = $new_name;
                    }
                }
            }
        }
    }

    $foto_utama = $uploaded_files[0] ?? '';

    if ($nama_tipe_kamar === '' || $harga_bulanan <= 0 || $selected_properti_id <= 0) {
        $notif_error = "Pastikan Nama Tipe Kamar, Properti Aktif, dan Tarif Sewa Bulanan sudah diisi dengan benar.";
    } else {
        $nama_esc = mysqli_real_escape_string($conn, $nama_tipe_kamar);
        $tipe_esc = mysqli_real_escape_string($conn, $tipe_kasur);
        $desk_esc = mysqli_real_escape_string($conn, $deskripsi_full);
        $fas_esc  = mysqli_real_escape_string($conn, $fasilitas_str);
        $foto_esc = mysqli_real_escape_string($conn, $foto_utama);
        $st_esc   = mysqli_real_escape_string($conn, $status_kamar);
        $val_esc  = mysqli_real_escape_string($conn, $status_validasi);

        $fields = ["`$fk_kamar`", "`$col_no_kamar`", "`status_validasi`"];
        $values = [$selected_properti_id, "'$nama_esc'", "'$val_esc'"];

        if ($col_tipe_kamar !== '') {
            $fields[] = "`$col_tipe_kamar`";
            $values[] = "'$tipe_esc'";
        }
        if ($col_harga_kamar !== '') {
            $fields[] = "`$col_harga_kamar`";
            $values[] = $harga_bulanan;
        }
        if ($col_jml_unit !== '') {
            $fields[] = "`$col_jml_unit`";
            $values[] = $jumlah_unit;
        }
        if ($col_fasilitas !== '') {
            $fields[] = "`$col_fasilitas`";
            $values[] = "'$fas_esc'";
        }
        if ($col_deskripsi !== '') {
            $fields[] = "`$col_deskripsi`";
            $values[] = "'$desk_esc'";
        }
        if ($col_foto !== '' && $foto_utama !== '') {
            $fields[] = "`$col_foto`";
            $values[] = "'$foto_esc'";
        }
        if ($col_status_kamar !== '') {
            $fields[] = "`$col_status_kamar`";
            $values[] = "'$st_esc'";
        }

        $sql_kamar = "INSERT INTO `$tbl_kamar` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";

        if (mysqli_query($conn, $sql_kamar)) {
            $new_id_kamar = (int)mysqli_insert_id($conn);

            if ($new_id_kamar > 0 && !empty($uploaded_files)) {
                $cols_fk = getTableColumns($conn, $tbl_foto_kamar);
                $col_file_fk = in_array('nama_file', $cols_fk, true) ? 'nama_file' : (in_array('path_foto', $cols_fk, true) ? 'path_foto' : 'foto');
                foreach ($uploaded_files as $file_item) {
                    $file_esc = mysqli_real_escape_string($conn, $file_item);
                    @mysqli_query($conn, "INSERT INTO `$tbl_foto_kamar` (`id_kamar`, `$col_file_fk`) VALUES ($new_id_kamar, '$file_esc')");
                }
            }

            $_SESSION['flash_sukses'] = "Tipe kamar '$nama_tipe_kamar' berhasil disimpan dengan " . count($uploaded_files) . " foto dan masuk ke antrean verifikasi Admin.";
            header("Location: kelola_kos.php?id_kamar=" . $new_id_kamar);
            exit;
        } else {
            $notif_error = "Gagal menyimpan data kamar: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Tipe Kamar Baru - KosLink Mitra</title>
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
                    <a href="kelola_kos.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <i class="fa-solid fa-door-open w-4 text-center"></i>
                        <span>Kelola Kos &amp; Kamar</span>
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
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5">
                    <i class="fa-solid fa-house-chimney text-blue-600 text-xs mr-2.5"></i>
                    <div>
                        <p class="text-[9px] font-bold text-slate-400 uppercase leading-none">PROPERTI AKTIF (1 MITRA = 1 PROPERTI)</p>
                        <span class="text-xs font-bold text-slate-800"><?= htmlspecialchars($nama_properti_aktif) ?></span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div class="text-right hidden sm:block">
                    <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_mitra) ?></p>
                    <p class="text-[11px] text-slate-400 font-medium">Mitra Terdaftar</p>
                </div>
            </div>
        </header>

        <!-- FORM UTAMA -->
        <form id="form-tambah-kamar" method="POST" action="" enctype="multipart/form-data" class="p-6 lg:p-8 max-w-[1200px] w-full mx-auto space-y-6">

            <input type="hidden" name="simpan_kamar" value="1">
            <input type="hidden" name="mode_simpan" id="mode_simpan" value="publish">

            <?php if ($notif_error): ?>
            <div class="bg-rose-50/70 border border-rose-200/80 text-rose-700 px-4 py-3.5 rounded-2xl flex items-start gap-3 shadow-sm mb-4 text-xs font-bold">
                <i class="fa-solid fa-circle-exclamation text-rose-500 mt-0.5"></i>
                <span><?= htmlspecialchars($notif_error) ?></span>
            </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Tambah Tipe Kamar Baru</h2>
                    <p class="text-xs text-slate-500 mt-1 max-w-2xl leading-relaxed">
                        Atur spesifikasi tipe kamar, inventaris fasilitas lengkap, galeri foto resolusi tinggi, dan penetapan harga sewa bulanan untuk properti <strong><?= htmlspecialchars($nama_properti_aktif) ?></strong>.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <a href="kelola_kos.php" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-bold transition shadow-sm">
                        Batal / Kembali
                    </a>
                    <button type="button" onclick="submitForm('draft')" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-bold transition shadow-sm inline-flex items-center gap-2">
                        <i class="fa-regular fa-floppy-disk text-slate-400"></i> Simpan Draft
                    </button>
                    <button type="button" onclick="submitForm('publish')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition shadow-sm shadow-blue-200 inline-flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Simpan &amp; Publikasikan
                    </button>
                </div>
            </div>

            <!-- LANGKAH 1 -->
            <section class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-6">
                <h3 class="text-base font-extrabold text-slate-900 pb-4 border-b border-slate-100">1. Informasi Dasar &amp; Dimensi Tipe Kamar</h3>
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                    <div class="lg:col-span-7">
                        <label class="text-xs font-bold text-slate-800 block mb-1.5">Nama Tipe Kamar <span class="text-rose-500">*</span></label>
                        <input type="text" name="nama_tipe_kamar" required placeholder="Contoh: Deluxe Executive King" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="lg:col-span-5">
                        <label class="text-xs font-bold text-slate-800 block mb-1.5">Jumlah Unit Tersedia <span class="text-rose-500">*</span></label>
                        <input type="number" name="jumlah_unit" min="1" max="50" required value="4" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                    <div class="lg:col-span-6">
                        <label class="block text-xs font-bold text-slate-800 mb-1.5">Ukuran Dimensi Kamar (Panjang &times; Lebar)</label>
                        <div class="flex items-center gap-2.5">
                            <input type="number" step="0.5" name="panjang" value="4.0" class="w-1/2 px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-900">
                            <input type="number" step="0.5" name="lebar" value="5.0" class="w-1/2 px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-900">
                        </div>
                    </div>
                    <div class="lg:col-span-6">
                        <label class="block text-xs font-bold text-slate-800 mb-1.5">Tipe Kasur &amp; Kapasitas</label>
                        <div class="grid grid-cols-2 gap-2.5">
                            <select name="tipe_kasur" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800">
                                <option value="Queen Size">Queen Size</option>
                                <option value="King Size">King Size</option>
                                <option value="Single Bed">Single Bed</option>
                            </select>
                            <select name="kapasitas" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800">
                                <option value="Maks. 2 Orang">Maks. 2 Orang</option>
                                <option value="Maks. 1 Orang">Maks. 1 Orang</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 pt-2">
                    <div class="lg:col-span-6">
                        <label class="block text-xs font-bold text-slate-800 mb-2">Penataan Kamar Mandi</label>
                        <div class="grid grid-cols-2 gap-2.5">
                            <label class="p-3 rounded-xl border border-slate-200 cursor-pointer flex items-center gap-2">
                                <input type="radio" name="kamar_mandi" value="Kamar Mandi Dalam" checked>
                                <span class="text-xs font-bold">Kamar Mandi Dalam</span>
                            </label>
                            <label class="p-3 rounded-xl border border-slate-200 cursor-pointer flex items-center gap-2">
                                <input type="radio" name="kamar_mandi" value="Kamar Mandi Luar">
                                <span class="text-xs font-bold">Kamar Mandi Luar</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <!-- LANGKAH 2: MULTI FOTO BERTAHAP ATAU SEKALIGUS -->
            <section class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-100">
                    <div>
                        <h3 class="text-base font-extrabold text-slate-900">2. Galeri Foto Kamar</h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">Tambahkan foto kamar dengan tombol <strong>+ Tambah Foto</strong> (maksimal 5 foto).</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span id="counter-foto" class="text-[11px] font-bold text-slate-600 bg-slate-100 px-3 py-1 rounded-full">0 / 5 Foto</span>
                        <button type="button" id="btn-tambah-foto" onclick="tambahSlotFoto()" class="bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-extrabold px-3 py-1.5 rounded-xl transition inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-plus"></i> Tambah Foto
                        </button>
                    </div>
                </div>

                <div id="daftar-slot-foto" class="space-y-2.5">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 bg-slate-50 border border-slate-200/80 p-3 rounded-2xl">
                        <span class="text-[11px] font-extrabold text-slate-600 shrink-0 w-24">Foto #1 (Utama)</span>
                        <input type="file" name="slot_foto[]" accept="image/jpeg,image/png,image/webp" onchange="rebuildSelectedFilesFromSlots()" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                    </div>
                </div>

                <p class="text-[11px] text-slate-400">
                    Format yang didukung: JPG, JPEG, PNG, WEBP (Maksimal 5 foto). Foto pertama yang tersimpan akan dijadikan foto utama.
                </p>

                <div id="preview-foto-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 hidden"></div>
            </section>

            <!-- LANGKAH 3: FASILITAS KAMAR & BERSAMA -->
            <section class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-5">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                    <div>
                        <h3 class="text-base font-extrabold text-slate-900">3. Fasilitas Kamar &amp; Bersama</h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">Pilihan di bawah ini otomatis terhubung dengan filter pencarian pencari kos di halaman utama.</p>
                    </div>
                    <span class="text-[11px] font-bold text-blue-600 bg-blue-50 px-3 py-1 rounded-full">Sinkron Filter Katalog</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
                    <?php
                    $daftar_fasilitas_kamar = [
                        ['AC Kamar', 'fa-snowflake'],
                        ['WiFi', 'fa-wifi'],
                        ['Kasur Springbed', 'fa-bed'],
                        ['Dapur Bersama', 'fa-kitchen-set'],
                        ['Parkir Mobil', 'fa-car'],
                        ['Parkir Motor', 'fa-motorcycle'],
                        ['TV Kamar', 'fa-tv'],
                        ['Lemari & Meja', 'fa-box-archive'],
                        ['Water Heater', 'fa-shower'],
                    ];
                    foreach ($daftar_fasilitas_kamar as [$nama_fas, $ikon_fas]):
                    ?>
                    <label class="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200/80 cursor-pointer hover:border-blue-300 transition">
                        <input type="checkbox" name="fasilitas[]" value="<?= htmlspecialchars($nama_fas) ?>" class="w-4 h-4 accent-blue-600 rounded">
                        <span class="font-bold text-slate-800">
                            <i class="fa-solid <?= $ikon_fas ?> text-blue-500 mr-1.5"></i><?= htmlspecialchars($nama_fas) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- LANGKAH 4 -->
            <section class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-6">
                <h3 class="text-base font-extrabold text-slate-900 pb-4 border-b border-slate-100">4. Tarif Sewa Bulanan</h3>
                <div class="max-w-md">
                    <label class="block text-xs font-bold text-slate-800 mb-1.5">Harga Sewa per Bulan (Rp) <span class="text-rose-500">*</span></label>
                    <div class="flex items-center border border-slate-200 rounded-xl overflow-hidden bg-white">
                        <span class="px-4 py-2.5 bg-slate-50 border-r border-slate-200 text-xs font-extrabold text-slate-500">Rp</span>
                        <input type="number" name="harga_bulanan" required placeholder="1750000" class="w-full px-4 py-2.5 text-xs font-extrabold text-slate-900 focus:outline-none">
                    </div>
                </div>
            </section>

            <div class="flex justify-end gap-3 pt-4">
                <a href="kelola_kos.php" class="bg-white border border-slate-200 px-6 py-3 rounded-xl text-xs font-bold text-slate-700">Batal</a>
                <button type="submit" onclick="document.getElementById('mode_simpan').value='publish'" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-xl text-xs font-extrabold shadow-md">Simpan &amp; Publikasikan Kamar</button>
            </div>

        </form>

    </div>

    <script>
        const MAX_FOTO = 5;
        let selectedFiles = [];

        function submitForm(mode) {
            document.getElementById('mode_simpan').value = mode;
            const form = document.getElementById('form-tambah-kamar');
            if (form.reportValidity()) {
                form.submit();
            }
        }

        function tambahSlotFoto() {
            const container = document.getElementById('daftar-slot-foto');
            const currentSlots = container.querySelectorAll('input[type="file"]').length;

            if (currentSlots >= MAX_FOTO) {
                alert('Maksimal ' + MAX_FOTO + ' slot foto.');
                return;
            }

            const idx = currentSlots + 1;
            const row = document.createElement('div');
            row.className = 'flex flex-col sm:flex-row sm:items-center gap-2 bg-slate-50 border border-slate-200/80 p-3 rounded-2xl';
            row.innerHTML = `
                <span class="text-[11px] font-extrabold text-slate-600 shrink-0 w-24">Foto #${idx}</span>
                <input type="file" name="slot_foto[]" accept="image/jpeg,image/png,image/webp" onchange="rebuildSelectedFilesFromSlots()" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                <button type="button" onclick="hapusSlot(this)" class="text-rose-600 hover:text-rose-700 text-xs font-bold px-2.5 py-1 rounded-lg hover:bg-rose-50 shrink-0">
                    <i class="fa-solid fa-trash"></i> Hapus
                </button>
            `;
            container.appendChild(row);
        }

        function hapusSlot(btn) {
            const row = btn.closest('div');
            if (row) {
                row.remove();
                rebuildSelectedFilesFromSlots();
            }
        }

        function rebuildSelectedFilesFromSlots() {
            const inputs = document.querySelectorAll('#daftar-slot-foto input[type="file"]');
            let collected = [];

            inputs.forEach((inp) => {
                if (inp.files && inp.files.length > 0) {
                    for (let i = 0; i < inp.files.length; i++) {
                        if (collected.length < MAX_FOTO) {
                            collected.push(inp.files[i]);
                        }
                    }
                }
            });

            selectedFiles = collected;
            renderPreviewGrid();
        }

        function renderPreviewGrid() {
            const grid = document.getElementById('preview-foto-grid');
            const counter = document.getElementById('counter-foto');
            counter.textContent = `${selectedFiles.length} / ${MAX_FOTO} Foto`;
            grid.innerHTML = '';

            if (selectedFiles.length === 0) {
                grid.classList.add('hidden');
                return;
            }

            grid.classList.remove('hidden');

            selectedFiles.forEach((file, i) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const card = document.createElement('div');
                    card.className = 'relative h-28 rounded-2xl overflow-hidden border border-slate-200 bg-slate-100';
                    card.innerHTML = `
                        <img src="${e.target.result}" alt="Preview ${i + 1}" class="w-full h-full object-cover">
                        <span class="absolute bottom-1.5 left-1.5 text-[10px] font-bold px-2 py-0.5 rounded-md ${i === 0 ? 'bg-blue-600 text-white' : 'bg-slate-900/75 text-white'}">
                            ${i === 0 ? 'Foto Utama' : 'Foto ' + (i + 1)}
                        </span>
                    `;
                    grid.appendChild(card);
                };
                reader.readAsDataURL(file);
            });
        }
    </script>
</body>
</html>