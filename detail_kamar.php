<?php
require 'koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_OFF);

$role_sesi    = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_logged_in = isset($_SESSION['role']) && !in_array($role_sesi, ['mitra', 'admin'], true);
$is_staff     = in_array($role_sesi, ['mitra', 'admin'], true) || !empty($_SESSION['id_admin']);
$id_user      = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? $_SESSION['id_pencari'] ?? $_SESSION['id'] ?? 0);
$nama_user    = $_SESSION['nama'] ?? $_SESSION['nama_lengkap'] ?? 'Pengguna KosLink';

function getColsDetail($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

// Pastikan kolom NIK tersedia di tabel penyewa & pengguna
$cols_penyewa_init = getColsDetail($conn, 'penyewa');
if (!empty($cols_penyewa_init) && !in_array('nik', $cols_penyewa_init, true)) {
    @mysqli_query($conn, "ALTER TABLE `penyewa` ADD COLUMN `nik` VARCHAR(20) NULL AFTER `nama_penyewa`");
}
$cols_pengguna_init = getColsDetail($conn, 'pengguna');
if (!empty($cols_pengguna_init) && !in_array('nik', $cols_pengguna_init, true)) {
    @mysqli_query($conn, "ALTER TABLE `pengguna` ADD COLUMN `nik` VARCHAR(20) NULL");
}

$id_kamar = (int)($_GET['id'] ?? $_GET['id_kamar'] ?? 0);
if ($id_kamar <= 0) {
    if (isset($_GET['ajax_stok'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_id']);
        exit;
    }
    header("Location: index.php");
    exit;
}

// Handler aksi: Hapus pengajuan lama (Pending) & lanjutkan sewa kamar baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_ganti_pending']) && $is_logged_in && $id_user > 0) {
    $id_pending_lama = (int)($_POST['id_pending_lama'] ?? 0);
    if ($id_pending_lama > 0) {
        @mysqli_query(
            $conn,
            "DELETE FROM `penyewa` 
             WHERE `id_penyewa` = $id_pending_lama 
               AND `id_pengguna` = $id_user 
               AND LOWER(TRIM(COALESCE(`status_hunian`, ''))) IN ('', 'pending', 'menunggu')
               AND LOWER(TRIM(COALESCE(`status_bayar`, ''))) NOT IN ('lunas', 'paid', 'terbayar')"
        );
    }
    header("Location: sewa.php?id=" . $id_kamar);
    exit;
}

$cols_kamar   = getColsDetail($conn, 'kamar');
$cols_prop    = getColsDetail($conn, 'properti_kos');
$cols_mitra   = getColsDetail($conn, 'mitra');
$cols_foto    = getColsDetail($conn, 'foto_kamar');
$cols_penyewa = getColsDetail($conn, 'penyewa');

$pk_kamar         = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : 'id';
$fk_kamar         = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : 'id_kos';
$col_sisa_k       = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : (in_array('jumlah_unit', $cols_kamar, true) ? 'jumlah_unit' : 'sisa_kamar');
$col_status_kamar = in_array('status_kamar', $cols_kamar, true) ? 'status_kamar' : (in_array('status', $cols_kamar, true) ? 'status' : '');
$pk_prop          = in_array('id_properti', $cols_prop, true) ? 'id_properti' : 'id_kos';

// SINKRONISASI REAL-TIME STOK & STATUS KAMAR DENGAN DATA PENYEWA
if (!empty($cols_penyewa) && in_array('id_kamar', $cols_penyewa, true)) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `stok_kamar_log` (
        `id_log` INT AUTO_INCREMENT PRIMARY KEY,
        `id_penyewa` INT NOT NULL,
        `id_kamar` INT NOT NULL,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_penyewa_kamar` (`id_penyewa`, `id_kamar`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pk_p = in_array('id_penyewa', $cols_penyewa, true) ? 'id_penyewa' : (in_array('id', $cols_penyewa, true) ? 'id' : '');
    if ($pk_p !== '') {
        $q_p_sync = @mysqli_query($conn, "SELECT * FROM `penyewa` WHERE `id_kamar` = $id_kamar");
        if ($q_p_sync) {
            while ($p_row = mysqli_fetch_assoc($q_p_sync)) {
                $pid_row = (int)($p_row[$pk_p] ?? 0);
                if ($pid_row <= 0) continue;

                $st_hunian = strtolower(trim((string)($p_row['status_hunian'] ?? '')));
                $st_bayar  = strtolower(trim((string)($p_row['status_bayar'] ?? '')));

                $sudah_keluar = in_array($st_hunian, ['keluar', 'selesai', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif'], true);
                $sudah_masuk  = !$sudah_keluar && (
                    in_array($st_hunian, ['aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true) ||
                    in_array($st_bayar, ['lunas', 'paid', 'terbayar', 'berhasil'], true)
                );

                $q_log = @mysqli_query($conn, "SELECT `id_log` FROM `stok_kamar_log` WHERE `id_penyewa` = $pid_row AND `id_kamar` = $id_kamar LIMIT 1");
                $has_log = ($q_log && mysqli_num_rows($q_log) > 0);

                if ($sudah_masuk && !$has_log) {
                    @mysqli_query($conn, "UPDATE `kamar` SET `$col_sisa_k` = GREATEST(0, `$col_sisa_k` - 1) WHERE `$pk_kamar` = $id_kamar");
                    @mysqli_query($conn, "INSERT IGNORE INTO `stok_kamar_log` (`id_penyewa`, `id_kamar`) VALUES ($pid_row, $id_kamar)");
                } elseif ($sudah_keluar && $has_log) {
                    @mysqli_query($conn, "UPDATE `kamar` SET `$col_sisa_k` = `$col_sisa_k` + 1 WHERE `$pk_kamar` = $id_kamar");
                    @mysqli_query($conn, "DELETE FROM `stok_kamar_log` WHERE `id_penyewa` = $pid_row AND `id_kamar` = $id_kamar");
                }
            }
        }
    }

    if ($col_status_kamar !== '') {
        @mysqli_query(
            $conn,
            "UPDATE `kamar` 
             SET `$col_status_kamar` = CASE WHEN `$col_sisa_k` > 0 THEN 'Tersedia' ELSE 'Penuh' END
             WHERE `$pk_kamar` = $id_kamar AND LOWER(COALESCE(`$col_status_kamar`, '')) != 'draft'"
        );
    }
}

// CEK STATUS PENYEWA LOGIN: Apakah sudah punya 1 kos AKTIF atau ada tagihan PENDING yang belum dibayar
$kos_aktif_user   = null;
$kos_pending_user = null;

if ($is_logged_in && $id_user > 0 && !empty($cols_penyewa)) {
    $q_akun = @mysqli_query($conn, "SELECT * FROM `pengguna` WHERE `id_pengguna` = $id_user LIMIT 1");
    $akun_row = ($q_akun && mysqli_num_rows($q_akun) > 0) ? mysqli_fetch_assoc($q_akun) : [];
    $nik_akun = preg_replace('/[^0-9]/', '', (string)($akun_row['nik'] ?? $_SESSION['nik'] ?? ''));
    $hp_akun  = preg_replace('/[^0-9]/', '', (string)($akun_row['no_hp'] ?? $_SESSION['no_hp'] ?? ''));

    $cond_id = ["`id_pengguna` = $id_user"];
    if ($nik_akun !== '' && in_array('nik', $cols_penyewa, true)) {
        $nik_esc = mysqli_real_escape_string($conn, $nik_akun);
        $cond_id[] = "`nik` = '$nik_esc'";
    }
    if ($hp_akun !== '' && in_array('no_hp', $cols_penyewa, true)) {
        $hp_esc = mysqli_real_escape_string($conn, $hp_akun);
        $cond_id[] = "REPLACE(REPLACE(REPLACE(`no_hp`, '-', ''), ' ', ''), '+', '') = '$hp_esc'";
    }

    $where_user_p = '(' . implode(' OR ', $cond_id) . ')';
    $q_hist = @mysqli_query($conn, "SELECT * FROM `penyewa` WHERE $where_user_p ORDER BY `id_penyewa` DESC");
    if ($q_hist) {
        while ($hr = mysqli_fetch_assoc($q_hist)) {
            $st_h = strtolower(trim((string)($hr['status_hunian'] ?? '')));
            $st_b = strtolower(trim((string)($hr['status_bayar'] ?? '')));
            $is_keluar = in_array($st_h, ['keluar', 'selesai', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif'], true);
            if ($is_keluar) continue;

            if ($kos_aktif_user === null && (in_array($st_h, ['aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true) || in_array($st_b, ['lunas', 'paid', 'terbayar', 'berhasil'], true))) {
                $kos_aktif_user = $hr;
            } elseif ($kos_pending_user === null && (int)$hr['id_pengguna'] === $id_user && in_array($st_h, ['', 'pending', 'menunggu'], true)) {
                $kos_pending_user = $hr;
            }
        }
    }
}

// Deteksi kolom tabel properti_kos & mitra
$col_nama_kos   = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : (in_array('nama_properti', $cols_prop, true) ? 'nama_properti' : '');
$col_lokasi     = in_array('lokasi', $cols_prop, true) ? 'lokasi' : '';
$col_alamat     = in_array('alamat', $cols_prop, true) ? 'alamat' : '';
$col_tipe_kos   = in_array('jenis_kos', $cols_prop, true) ? 'jenis_kos' : (in_array('tipe_kos', $cols_prop, true) ? 'tipe_kos' : '');
$col_sertifikat = in_array('jenis_sertifikat', $cols_prop, true) ? 'jenis_sertifikat' : '';
$col_mitra_fk   = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : '';

$pk_mitra       = in_array('id_mitra', $cols_mitra, true) ? 'id_mitra' : 'id';
$col_nama_mitra = in_array('nama_mitra', $cols_mitra, true) ? 'nama_mitra' : (in_array('nama', $cols_mitra, true) ? 'nama' : '');
$col_hp_mitra   = in_array('no_hp', $cols_mitra, true) ? 'no_hp' : (in_array('telepon', $cols_mitra, true) ? 'telepon' : '');

$select_parts = ["k.*"];
$select_parts[] = $col_nama_kos !== '' ? "p.`$col_nama_kos` AS prop_nama_kos" : "'Properti KosLink' AS prop_nama_kos";
$select_parts[] = $col_lokasi !== '' ? "p.`$col_lokasi` AS prop_lokasi" : "'' AS prop_lokasi";
$select_parts[] = $col_alamat !== '' ? "p.`$col_alamat` AS prop_alamat" : "'' AS prop_alamat";
$select_parts[] = $col_tipe_kos !== '' ? "p.`$col_tipe_kos` AS prop_tipe_kos" : "'Campur' AS prop_tipe_kos";
$select_parts[] = $col_sertifikat !== '' ? "p.`$col_sertifikat` AS prop_sertifikat" : "'Terverifikasi' AS prop_sertifikat";

$join_mitra = "";
if (!empty($cols_mitra) && $col_mitra_fk !== '') {
    $join_mitra = "LEFT JOIN mitra m ON p.`$col_mitra_fk` = m.`$pk_mitra`";
    $select_parts[] = $col_nama_mitra !== '' ? "m.`$col_nama_mitra` AS nama_mitra" : "'Mitra Terverifikasi' AS nama_mitra";
    $select_parts[] = $col_hp_mitra !== '' ? "m.`$col_hp_mitra` AS hp_mitra" : "'' AS hp_mitra";
} else {
    $select_parts[] = "'Mitra Terverifikasi' AS nama_mitra";
    $select_parts[] = "'' AS hp_mitra";
}

$sql_detail = "
    SELECT " . implode(", ", $select_parts) . "
    FROM kamar k
    LEFT JOIN properti_kos p ON k.`$fk_kamar` = p.`$pk_prop`
    $join_mitra
    WHERE k.`$pk_kamar` = $id_kamar
    LIMIT 1
";

$q_detail = @mysqli_query($conn, $sql_detail);
$kamar = $q_detail ? mysqli_fetch_assoc($q_detail) : null;

if (!$kamar) {
    if (isset($_GET['ajax_stok'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_found']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$nama_kamar      = $kamar['nama_kamar'] ?? $kamar['nomor_kamar'] ?? ('Tipe Kamar #' . $id_kamar);
$tipe_kasur      = $kamar['tipe_kamar'] ?? 'Standard';
$harga_bulan     = (int)($kamar['harga_bulan'] ?? $kamar['harga_bulanan'] ?? $kamar['harga_sewa'] ?? $kamar['harga'] ?? 0);
$sisa_kamar      = max(0, (int)($kamar['sisa_kamar'] ?? $kamar['jumlah_unit'] ?? 0));
$status_kmr      = strtolower(trim((string)($kamar['status_kamar'] ?? $kamar['status'] ?? 'tersedia')));
$status_validasi = strtolower(trim((string)($kamar['status_validasi'] ?? 'disetujui')));

if (!$is_staff && in_array($status_validasi, ['pending', 'revisi', 'ditolak'], true)) {
    header("Location: index.php");
    exit;
}

$is_tersedia = ($sisa_kamar > 0) && !in_array($status_kmr, ['terisi', 'occupied', 'disewa', 'penuh', 'draft'], true);

if (isset($_GET['ajax_stok'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'sisa_kamar'  => $sisa_kamar,
        'is_tersedia' => $is_tersedia,
        'status_text' => $is_tersedia ? "Tersedia ({$sisa_kamar} kamar)" : "Penuh"
    ]);
    exit;
}

$nama_kos    = !empty($kamar['prop_nama_kos']) ? $kamar['prop_nama_kos'] : 'Properti KosLink';
$lokasi_kos  = !empty($kamar['prop_lokasi']) ? $kamar['prop_lokasi'] : (!empty($kamar['prop_alamat']) ? $kamar['prop_alamat'] : 'Jimbaran, Kuta Selatan');
$alamat_kos  = !empty($kamar['prop_alamat']) ? $kamar['prop_alamat'] : $lokasi_kos;
$tipe_kos    = ucfirst(strtolower(trim((string)(!empty($kamar['prop_tipe_kos']) ? $kamar['prop_tipe_kos'] : 'Campur'))));
$nama_mitra  = !empty($kamar['nama_mitra']) ? $kamar['nama_mitra'] : 'Mitra Terverifikasi';
$hp_mitra    = (string)($kamar['hp_mitra'] ?? '');
$sertifikat  = !empty($kamar['prop_sertifikat']) ? $kamar['prop_sertifikat'] : 'Terverifikasi';

$fasilitas_raw = (string)($kamar['fasilitas'] ?? '');
$fasilitas_list = array_values(array_filter(array_map('trim', explode(',', $fasilitas_raw))));
if (empty($fasilitas_list)) {
    $fasilitas_list = ['Kasur Nyaman', 'WiFi', 'Kamar Mandi Bersih'];
}

$deskripsi_raw = trim((string)($kamar['deskripsi'] ?? ''));
$dimensi_text  = 'Standar Nyaman';
$deskripsi_isi = $deskripsi_raw;

if (preg_match('/^Luas\s+([^\n\.]+)\.\s*(.*)$/s', $deskripsi_raw, $m_desc)) {
    $dimensi_text  = trim($m_desc[1]);
    $deskripsi_isi = trim($m_desc[2]);
}
if ($deskripsi_isi === '') {
    $deskripsi_isi = "Kamar bersih, sirkulasi udara baik, dan siap huni di {$nama_kos}. Lingkungan aman serta strategis dekat area kampus dan pusat aktivitas harian.";
}

$galeri_foto = [];
$foto_utama_col = $kamar['foto_kamar'] ?? $kamar['foto'] ?? '';
if ($foto_utama_col !== '') {
    $galeri_foto[] = 'uploads/' . basename(ltrim($foto_utama_col, '/'));
}

if (!empty($cols_foto)) {
    $col_file_foto = in_array('nama_file', $cols_foto, true) ? 'nama_file' : (in_array('foto', $cols_foto, true) ? 'foto' : 'file');
    $q_gal = @mysqli_query($conn, "SELECT `$col_file_foto` AS fname FROM foto_kamar WHERE id_kamar = $id_kamar ORDER BY id_foto ASC");
    if ($q_gal) {
        while ($gf = mysqli_fetch_assoc($q_gal)) {
            if (!empty($gf['fname'])) {
                $path = 'uploads/' . basename(ltrim($gf['fname'], '/'));
                if (!in_array($path, $galeri_foto, true)) {
                    $galeri_foto[] = $path;
                }
            }
        }
    }
}

if (empty($galeri_foto)) {
    $galeri_foto[] = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1000&q=80';
}

function iconFasilitas($nama) {
    $n = strtolower($nama);
    if (strpos($n, 'ac') !== false) return 'fa-snowflake';
    if (strpos($n, 'wifi') !== false) return 'fa-wifi';
    if (strpos($n, 'mandi') !== false) return 'fa-bath';
    if (strpos($n, 'kasur') !== false || strpos($n, 'queen') !== false || strpos($n, 'king') !== false || strpos($n, 'single') !== false || strpos($n, 'bed') !== false) return 'fa-bed';
    if (strpos($n, 'dapur') !== false) return 'fa-kitchen-set';
    if (strpos($n, 'mobil') !== false) return 'fa-car';
    if (strpos($n, 'motor') !== false) return 'fa-motorcycle';
    if (strpos($n, 'tv') !== false) return 'fa-tv';
    if (strpos($n, 'lemari') !== false || strpos($n, 'meja') !== false) return 'fa-box-archive';
    if (strpos($n, 'heater') !== false) return 'fa-shower';
    return 'fa-circle-check';
}

$badge_gender_cls = 'bg-slate-800 text-white';
if (stripos($tipe_kos, 'putri') !== false) $badge_gender_cls = 'bg-rose-500 text-white';
elseif (stripos($tipe_kos, 'putra') !== false) $badge_gender_cls = 'bg-blue-600 text-white';

$url_sewa  = "sewa.php?id=" . $id_kamar;
$url_login = "auth/login.php?redirect=" . urlencode($url_sewa);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nama_kamar) ?> - <?= htmlspecialchars($nama_kos) ?> | KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- NAVBAR ATAS -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                <a href="index.php" class="flex items-center gap-2.5">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                        <i class="fa-solid fa-building text-sm"></i>
                    </div>
                    <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
                </a>
                <a href="index.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Katalog
                </a>
            </div>

            <div class="flex items-center gap-3">
                <?php if (!$is_logged_in): ?>
                    <a href="<?= htmlspecialchars($url_login) ?>" class="text-xs font-bold text-slate-700 hover:text-blue-600 px-3 py-2 transition">Masuk</a>
                    <a href="auth/register.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-xl shadow-sm transition">Daftar</a>
                <?php else: ?>
                    <div class="text-right hidden sm:block">
                        <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_user) ?></p>
                        <p class="text-[10px] text-blue-600 font-bold">Akun Pencari Kos</p>
                    </div>
                    <a href="penyewa/dashboard.php" class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 border border-blue-200 flex items-center justify-center text-xs font-bold">
                        <i class="fa-regular fa-user"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA DETAIL KAMAR -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6 flex-1">

        <!-- BREADCRUMB -->
        <nav class="flex flex-wrap items-center gap-2 text-[11px] text-slate-400 font-semibold">
            <a href="index.php" class="hover:text-blue-600">Beranda</a>
            <i class="fa-solid fa-chevron-right text-[9px]"></i>
            <span><?= htmlspecialchars($lokasi_kos) ?></span>
            <i class="fa-solid fa-chevron-right text-[9px]"></i>
            <span class="text-slate-700 font-bold"><?= htmlspecialchars($nama_kos) ?> &bull; <?= htmlspecialchars($nama_kamar) ?></span>
        </nav>

        <!-- GALERI FOTO KAMAR -->
        <section class="bg-white border border-slate-200/80 rounded-3xl p-4 sm:p-5 shadow-2xs space-y-3">
            <div class="relative h-72 sm:h-96 md:h-[430px] rounded-2xl overflow-hidden bg-slate-100">
                <img id="foto-utama-preview"
                     src="<?= htmlspecialchars($galeri_foto[0]) ?>"
                     onerror="this.src='https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1000&q=80'"
                     alt="<?= htmlspecialchars($nama_kamar) ?>"
                     class="w-full h-full object-cover transition-all duration-300">

                <div class="absolute top-3.5 left-3.5 flex flex-wrap items-center gap-2">
                    <span class="bg-white/95 text-blue-700 text-xs font-extrabold px-3 py-1 rounded-lg shadow-xs inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-shield-check"></i> Properti Terverifikasi
                    </span>
                    <span class="<?= $badge_gender_cls ?> text-xs font-extrabold px-3 py-1 rounded-lg shadow-xs">
                        Kos <?= htmlspecialchars($tipe_kos) ?>
                    </span>
                </div>

                <div class="absolute bottom-3.5 right-3.5 bg-slate-900/80 backdrop-blur-xs text-white text-xs font-bold px-3 py-1 rounded-lg">
                    <i class="fa-regular fa-images mr-1"></i> <?= count($galeri_foto) ?> Foto Kamar
                </div>
            </div>

            <?php if (count($galeri_foto) > 1): ?>
            <div class="grid grid-cols-3 sm:grid-cols-5 gap-2.5">
                <?php foreach ($galeri_foto as $idx_f => $src_f): ?>
                <button type="button"
                        onclick="gantiFotoUtama('<?= htmlspecialchars($src_f, ENT_QUOTES) ?>', this)"
                        class="thumb-foto relative h-20 sm:h-24 rounded-xl overflow-hidden border-2 <?= $idx_f === 0 ? 'border-blue-600 ring-2 ring-blue-200' : 'border-transparent opacity-75 hover:opacity-100' ?> transition">
                    <img src="<?= htmlspecialchars($src_f) ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=400&q=80'"
                         alt="Thumbnail <?= $idx_f + 1 ?>"
                         class="w-full h-full object-cover">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- GRID 2 KOLOM: INFORMASI LENGKAP KIRI & KARTU BOOKING KANAN -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- KOLOM KIRI -->
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <p class="text-xs font-extrabold text-blue-600 uppercase tracking-wider">
                                <?= htmlspecialchars($nama_kos) ?>
                            </p>
                            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                                <?= htmlspecialchars($nama_kamar) ?>
                            </h1>
                            <p class="text-xs text-slate-500 flex items-center gap-1.5 pt-0.5">
                                <i class="fa-solid fa-location-dot text-blue-600"></i>
                                <span><?= htmlspecialchars($alamat_kos) ?></span>
                            </p>
                        </div>

                        <div id="badge-ketersediaan-header" class="text-right">
                            <?php if ($is_tersedia): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-extrabold">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    Tersedia (<?= $sisa_kamar ?> Unit)
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-50 border border-rose-200 text-rose-700 text-xs font-extrabold">
                                    Unit Penuh
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                        <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                            <span class="text-[10px] font-bold text-slate-400 uppercase block">Dimensi &amp; Kapasitas</span>
                            <span class="text-xs font-extrabold text-slate-900 mt-1 block"><?= htmlspecialchars($dimensi_text) ?></span>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                            <span class="text-[10px] font-bold text-slate-400 uppercase block">Tipe Kasur</span>
                            <span class="text-xs font-extrabold text-slate-900 mt-1 block"><?= htmlspecialchars($tipe_kasur) ?></span>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                            <span class="text-[10px] font-bold text-slate-400 uppercase block">Kategori Kos</span>
                            <span class="text-xs font-extrabold text-slate-900 mt-1 block">Kos <?= htmlspecialchars($tipe_kos) ?></span>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                            <span class="text-[10px] font-bold text-slate-400 uppercase block">Legalitas Lahan</span>
                            <span class="text-xs font-extrabold text-blue-600 mt-1 block"><?= htmlspecialchars($sertifikat) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Fasilitas Kamar & Properti -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h2 class="text-base font-extrabold text-slate-900">Fasilitas Kamar &amp; Bersama</h2>
                        <span class="text-[11px] font-bold text-slate-400"><?= count($fasilitas_list) ?> Fasilitas Tersedia</span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <?php foreach ($fasilitas_list as $fas): ?>
                        <div class="flex items-center gap-3 p-3 rounded-2xl bg-slate-50 border border-slate-200/70">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xs shrink-0">
                                <i class="fa-solid <?= iconFasilitas($fas) ?>"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-800"><?= htmlspecialchars($fas) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Deskripsi Lengkap -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-3">
                    <h2 class="text-base font-extrabold text-slate-900 border-b border-slate-100 pb-3">Deskripsi &amp; Informasi Tambahan</h2>
                    <p class="text-xs sm:text-sm text-slate-600 leading-relaxed whitespace-pre-line">
                        <?= htmlspecialchars($deskripsi_isi) ?>
                    </p>
                </div>

                <!-- Profil Pengelola / Mitra -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-3.5">
                        <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-base shrink-0">
                            <?= strtoupper(substr($nama_mitra, 0, 2)) ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-extrabold text-slate-900"><?= htmlspecialchars($nama_mitra) ?></h3>
                                <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-600 text-[10px] font-extrabold">Mitra Resmi</span>
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5">Pengelola properti <?= htmlspecialchars($nama_kos) ?> &bull; Transaksi dilindungi Rekber KosLink</p>
                        </div>
                    </div>

                    <?php if ($hp_mitra !== ''):
                        $wa_num = preg_replace('/[^0-9]/', '', $hp_mitra);
                        if (strpos($wa_num, '0') === 0) $wa_num = '62' . substr($wa_num, 1);
                    ?>
                    <a href="https://wa.me/<?= htmlspecialchars($wa_num) ?>?text=<?= urlencode("Halo, saya tertarik dengan kamar {$nama_kamar} di {$nama_kos} yang ada di KosLink.") ?>"
                       target="_blank"
                       class="px-4 py-2.5 rounded-xl border border-slate-200 hover:border-emerald-400 hover:bg-emerald-50 text-slate-700 hover:text-emerald-700 text-xs font-bold transition inline-flex items-center justify-center gap-2 shrink-0">
                        <i class="fa-brands fa-whatsapp text-emerald-600 text-sm"></i> Tanya Pemilik
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KOLOM KANAN: KARTU PENGAJUAN SEWA (STICKY) -->
            <div class="lg:col-span-4 lg:sticky lg:top-24 space-y-4">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-md space-y-5">
                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Harga Sewa Bulanan</span>
                        <div class="flex items-baseline gap-1 mt-1">
                            <span class="text-2xl sm:text-3xl font-extrabold text-slate-900">
                                Rp <?= number_format($harga_bulan, 0, ',', '.') ?>
                            </span>
                            <span class="text-xs font-semibold text-slate-400">/ bulan</span>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70 space-y-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Status Unit</span>
                            <span id="status-unit-sidebar" class="font-extrabold <?= $is_tersedia ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $is_tersedia ? "Tersedia ({$sisa_kamar} kamar)" : "Penuh" ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Batas Sewa Per Akun</span>
                            <span class="font-bold text-slate-800">Maks. 1 Kos Aktif</span>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/70">
                            <span class="font-bold text-slate-700">Estimasi Pembayaran Awal</span>
                            <span class="font-extrabold text-blue-600">Rp <?= number_format($harga_bulan, 0, ',', '.') ?></span>
                        </div>
                    </div>

                    <div id="action-booking-container">
                        <?php if (!$is_tersedia): ?>
                            <button type="button" disabled class="w-full py-3.5 rounded-2xl bg-slate-200 text-slate-500 text-xs font-extrabold cursor-not-allowed">
                                Kamar Sudah Penuh
                            </button>
                        <?php elseif ($is_logged_in && $kos_aktif_user !== null): ?>
                            <!-- KUNCI: SUDAH MEMILIKI 1 KOS AKTIF -->
                            <div class="p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-[11px] space-y-2">
                                <p class="font-extrabold text-rose-900"><i class="fa-solid fa-ban mr-1"></i> Anda Sudah Menyewa 1 Kos Aktif</p>
                                <p class="leading-relaxed">
                                    Akun / data pribadi Anda sedang aktif menyewa <strong><?= htmlspecialchars($kos_aktif_user['no_kamar'] ?? 'kamar kos') ?></strong>. Sesuai kebijakan KosLink, 1 penyewa hanya dapat menyewa maksimal 1 kos.
                                </p>
                                <a href="penyewa/dashboard.php" class="w-full py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-extrabold transition flex items-center justify-center gap-1.5">
                                    <i class="fa-solid fa-house-user"></i> Lihat Kos Aktif Saya
                                </a>
                            </div>
                        <?php elseif ($is_logged_in && $kos_pending_user !== null): ?>
                            <!-- KUNCI: ADA PEMBAYARAN PENDING YANG BELUM SELESAI -->
                            <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-[11px] space-y-2.5">
                                <p class="font-extrabold text-amber-950">
                                    <i class="fa-solid fa-clock text-amber-600 mr-1"></i>
                                    Pembayaran untuk "<?= htmlspecialchars($kos_pending_user['no_kamar'] ?? 'Kamar Sebelumnya') ?>" belum selesai
                                </p>
                                <p class="text-amber-800 leading-relaxed">
                                    Anda memiliki pengajuan sewa yang masih menunggu pembayaran. Pilih salah satu opsi di bawah untuk melanjutkan:
                                </p>
                                <div class="space-y-2 pt-1">
                                    <a href="penyewa/bayar.php?id_sewa=<?= (int)$kos_pending_user['id_penyewa'] ?>" class="w-full py-2.5 px-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold transition flex items-center justify-center gap-1.5 text-center">
                                        <i class="fa-solid fa-wallet"></i> Lanjutkan Pembayaran Lama
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Pengajuan pembayaran lama untuk <?= htmlspecialchars($kos_pending_user['no_kamar'] ?? 'kamar sebelumnya', ENT_QUOTES) ?> akan dihapus dan diganti dengan kamar ini. Lanjutkan?');">
                                        <input type="hidden" name="aksi_ganti_pending" value="1">
                                        <input type="hidden" name="id_pending_lama" value="<?= (int)$kos_pending_user['id_penyewa'] ?>">
                                        <button type="submit" class="w-full py-2.5 px-3 rounded-xl bg-white hover:bg-rose-50 text-rose-700 border border-rose-200 text-xs font-extrabold transition flex items-center justify-center gap-1.5">
                                            <i class="fa-solid fa-rotate"></i> Lanjutkan Pembayaran Baru (Hapus Yang Lama)
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php elseif ($is_logged_in): ?>
                            <a href="<?= htmlspecialchars($url_sewa) ?>" class="w-full py-3.5 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold shadow-md shadow-blue-200 transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-calendar-check"></i>
                                <span>Lanjut Ajukan Sewa / Booking</span>
                            </a>
                        <?php else: ?>
                            <button type="button" onclick="bukaModalLogin()" class="w-full py-3.5 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold shadow-md shadow-blue-200 transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-lock"></i>
                                <span>Ajukan Sewa / Booking Sekarang</span>
                            </button>
                            <p class="text-[11px] text-center text-slate-400 mt-2.5">
                                Anda perlu <a href="<?= htmlspecialchars($url_login) ?>" class="text-blue-600 font-bold hover:underline">masuk ke akun</a> terlebih dahulu untuk melanjutkan pemesanan.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="pt-2 border-t border-slate-100 flex items-start gap-2.5 text-[11px] text-slate-500">
                        <i class="fa-solid fa-shield-halved text-blue-600 mt-0.5"></i>
                        <span>Dana sewa ditahan aman di Rekber KosLink dan baru dicairkan ke pemilik setelah Anda check-in kamar.</span>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- MODAL PERINGATAN LOGIN SEBELUM BOOKING -->
    <div id="modal-wajib-login" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-5 text-center">
            <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl mx-auto">
                <i class="fa-solid fa-user-lock"></i>
            </div>
            <div class="space-y-1.5">
                <h3 class="text-lg font-extrabold text-slate-900">Masuk Terlebih Dahulu</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Untuk melanjutkan pengajuan sewa kamar <strong><?= htmlspecialchars($nama_kamar) ?></strong>, silakan masuk ke akun KosLink Anda atau daftar akun baru terlebih dahulu.
                </p>
            </div>
            <div class="flex flex-col sm:flex-row gap-2.5 pt-1">
                <a href="<?= htmlspecialchars($url_login) ?>" class="flex-1 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold shadow-sm transition">
                    Masuk ke Akun
                </a>
                <a href="auth/register.php" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-extrabold transition">
                    Daftar Akun Baru
                </a>
            </div>
            <button type="button" onclick="tutupModalLogin()" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                Nanti Saja, Lihat Detail Dulu
            </button>
        </div>
    </div>

    <script>
        function gantiFotoUtama(src, btn) {
            const img = document.getElementById('foto-utama-preview');
            if (img) img.src = src;

            document.querySelectorAll('.thumb-foto').forEach(el => {
                el.className = 'thumb-foto relative h-20 sm:h-24 rounded-xl overflow-hidden border-2 border-transparent opacity-75 hover:opacity-100 transition';
            });
            if (btn) {
                btn.className = 'thumb-foto relative h-20 sm:h-24 rounded-xl overflow-hidden border-2 border-blue-600 ring-2 ring-blue-200 transition';
            }
        }

        function bukaModalLogin() {
            const m = document.getElementById('modal-wajib-login');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function tutupModalLogin() {
            const m = document.getElementById('modal-wajib-login');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
    </script>
</body>
</html>