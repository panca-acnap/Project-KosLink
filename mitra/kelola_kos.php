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

mysqli_report(MYSQLI_REPORT_OFF);

$id_mitra   = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$nama_mitra = $_SESSION['nama'] ?? 'Mitra KosLink';

function resolveTableName($conn, array $candidates) {
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

function getTableColumnsSafe($conn, $table) {
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

$tbl_prop       = resolveTableName($conn, ['properti_kos', 'Properti_Kos', 'properti', 'Properti']);
$tbl_kamar      = resolveTableName($conn, ['kamar', 'Kamar']);
$tbl_penyewa    = resolveTableName($conn, ['penyewa', 'Penyewa']);
$tbl_foto_kamar = resolveTableName($conn, ['foto_kamar', 'Foto_Kamar']);

// 1. PASTIKAN TABEL GALERI FOTO & LOG STOK KAMAR TERSEDIA
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `$tbl_foto_kamar` (
    `id_foto` INT AUTO_INCREMENT PRIMARY KEY,
    `id_kamar` INT NOT NULL,
    `nama_file` VARCHAR(255) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `stok_kamar_log` (
    `id_log` INT AUTO_INCREMENT PRIMARY KEY,
    `id_penyewa` INT NOT NULL,
    `id_kamar` INT NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_penyewa_kamar` (`id_penyewa`, `id_kamar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cols_prop  = getTableColumnsSafe($conn, $tbl_prop);
$cols_kamar = getTableColumnsSafe($conn, $tbl_kamar);

$pk_prop      = in_array('id_properti', $cols_prop, true) ? 'id_properti' : (in_array('id_kos', $cols_prop, true) ? 'id_kos' : 'id');
$col_nama_kos = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : (in_array('nama_properti', $cols_prop, true) ? 'nama_properti' : 'nama');
$fk_mitra     = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : (in_array('id_pengguna', $cols_prop, true) ? 'id_pengguna' : '');

$pk_kamar         = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : 'id';
$fk_k_prop        = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : (in_array('id_kos', $cols_kamar, true) ? 'id_kos' : '');
$col_sisa_k       = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : (in_array('jumlah_unit', $cols_kamar, true) ? 'jumlah_unit' : 'sisa_kamar');
$col_status_kamar = in_array('status_kamar', $cols_kamar, true) ? 'status_kamar' : (in_array('status', $cols_kamar, true) ? 'status' : '');

// 2. Ambil 1 properti milik mitra yang login (1 akun = 1 properti)
$properti_aktif = null;
if ($id_mitra > 0 && $fk_mitra !== '' && !empty($cols_prop)) {
    $q_prop = @mysqli_query($conn, "SELECT * FROM `$tbl_prop` WHERE `$fk_mitra` = $id_mitra ORDER BY `$pk_prop` ASC LIMIT 1");
    if ($q_prop && ($rp = mysqli_fetch_assoc($q_prop))) {
        $properti_aktif = $rp;
    }
}

$selected_properti_id = $properti_aktif ? (int)($properti_aktif[$pk_prop] ?? 0) : 0;
$nama_properti_aktif = ($properti_aktif && isset($properti_aktif[$col_nama_kos]) && $properti_aktif[$col_nama_kos] !== '')
    ? (string)$properti_aktif[$col_nama_kos]
    : 'Belum Ada Properti';

// Hitung jumlah penghuni aktif per kamar & sinkronisasi real-time sisa kamar + status kamar
$terisi_by_kamar = [];
if ($selected_properti_id > 0 && $fk_k_prop !== '' && !empty($cols_kamar)) {
    $cols_p = getTableColumnsSafe($conn, $tbl_penyewa);
    if (!empty($cols_p) && in_array('id_kamar', $cols_p, true)) {
        $q_km_ids = @mysqli_query($conn, "SELECT `$pk_kamar`, `$col_sisa_k`" . ($col_status_kamar !== '' ? ", `$col_status_kamar`" : "") . " FROM `$tbl_kamar` WHERE `$fk_k_prop` = $selected_properti_id");
        $kamar_ids_mitra = [];
        if ($q_km_ids) {
            while ($rk_id = mysqli_fetch_assoc($q_km_ids)) {
                $kid_tmp = (int)($rk_id[$pk_kamar] ?? 0);
                if ($kid_tmp > 0) {
                    $kamar_ids_mitra[] = $kid_tmp;
                    $terisi_by_kamar[$kid_tmp] = 0;
                }
            }
        }

        if (!empty($kamar_ids_mitra)) {
            $in_km = implode(',', $kamar_ids_mitra);
            $pk_p  = in_array('id_penyewa', $cols_p, true) ? 'id_penyewa' : (in_array('id', $cols_p, true) ? 'id' : '');
            if ($pk_p !== '') {
                $q_all_p = @mysqli_query($conn, "SELECT * FROM `$tbl_penyewa` WHERE `id_kamar` IN ($in_km)");
                if ($q_all_p) {
                    while ($p_row = mysqli_fetch_assoc($q_all_p)) {
                        $pid_row = (int)($p_row[$pk_p] ?? 0);
                        $kid_row = (int)($p_row['id_kamar'] ?? 0);
                        if ($pid_row <= 0 || $kid_row <= 0) continue;

                        $st_hunian = strtolower(trim((string)($p_row['status_hunian'] ?? '')));
                        $st_bayar  = strtolower(trim((string)($p_row['status_bayar'] ?? '')));

                        $sudah_keluar = in_array($st_hunian, ['keluar', 'selesai', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif'], true);
                        $sudah_masuk  = !$sudah_keluar && (
                            in_array($st_hunian, ['aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true) ||
                            in_array($st_bayar, ['lunas', 'paid', 'terbayar', 'berhasil'], true)
                        );

                        if ($sudah_masuk) {
                            $terisi_by_kamar[$kid_row] = ($terisi_by_kamar[$kid_row] ?? 0) + 1;
                        }

                        $q_log = @mysqli_query($conn, "SELECT `id_log` FROM `stok_kamar_log` WHERE `id_penyewa` = $pid_row AND `id_kamar` = $kid_row LIMIT 1");
                        $has_log = ($q_log && mysqli_num_rows($q_log) > 0);

                        if ($sudah_masuk && !$has_log) {
                            @mysqli_query($conn, "UPDATE `$tbl_kamar` SET `$col_sisa_k` = GREATEST(0, `$col_sisa_k` - 1) WHERE `$pk_kamar` = $kid_row");
                            @mysqli_query($conn, "INSERT IGNORE INTO `stok_kamar_log` (`id_penyewa`, `id_kamar`) VALUES ($pid_row, $kid_row)");
                        } elseif ($sudah_keluar && $has_log) {
                            @mysqli_query($conn, "UPDATE `$tbl_kamar` SET `$col_sisa_k` = `$col_sisa_k` + 1 WHERE `$pk_kamar` = $kid_row");
                            @mysqli_query($conn, "DELETE FROM `stok_kamar_log` WHERE `id_penyewa` = $pid_row AND `id_kamar` = $kid_row");
                        }
                    }
                }
            }

            // Sinkronkan status_kamar ('Tersedia' vs 'Penuh') berdasarkan sisa_kamar terkini
            if ($col_status_kamar !== '') {
                @mysqli_query(
                    $conn,
                    "UPDATE `$tbl_kamar` 
                     SET `$col_status_kamar` = CASE WHEN `$col_sisa_k` > 0 THEN 'Tersedia' ELSE 'Penuh' END
                     WHERE `$fk_k_prop` = $selected_properti_id AND LOWER(COALESCE(`$col_status_kamar`, '')) != 'draft'"
                );
            }
        }
    }
}

$col_nama_kamar  = in_array('nama_kamar', $cols_kamar, true) ? 'nama_kamar' : (in_array('nomor_kamar', $cols_kamar, true) ? 'nomor_kamar' : 'nama_kamar');
$col_harga_kamar = in_array('harga_bulan', $cols_kamar, true) ? 'harga_bulan' : (in_array('harga_bulanan', $cols_kamar, true) ? 'harga_bulanan' : (in_array('harga_sewa', $cols_kamar, true) ? 'harga_sewa' : 'harga_bulan'));
$col_fas_kamar   = in_array('fasilitas', $cols_kamar, true) ? 'fasilitas' : '';
$col_foto_kamar  = in_array('foto_kamar', $cols_kamar, true) ? 'foto_kamar' : (in_array('foto', $cols_kamar, true) ? 'foto' : 'foto_kamar');

// 2B. PROSES HAPUS KAMAR (DARI POP-UP KONFIRMASI)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_kamar']) && $selected_properti_id > 0 && $fk_k_prop !== '') {
    $id_kamar_hapus = (int)($_POST['id_kamar_hapus'] ?? 0);

    if ($id_kamar_hapus > 0) {
        $q_cek_milik = @mysqli_query(
            $conn,
            "SELECT * FROM `$tbl_kamar` WHERE `$pk_kamar` = $id_kamar_hapus AND `$fk_k_prop` = $selected_properti_id LIMIT 1"
        );

        if ($q_cek_milik && ($row_hapus = mysqli_fetch_assoc($q_cek_milik))) {
            $nama_dihapus = (string)($row_hapus[$col_nama_kamar] ?? 'Kamar');

            $ada_penghuni_aktif = false;
            $cols_p_cek = getTableColumnsSafe($conn, $tbl_penyewa);
            if (!empty($cols_p_cek) && in_array('id_kamar', $cols_p_cek, true) && in_array('status_hunian', $cols_p_cek, true)) {
                $q_aktif_p = @mysqli_query(
                    $conn,
                    "SELECT COUNT(*) AS jml FROM `$tbl_penyewa` 
                     WHERE `id_kamar` = $id_kamar_hapus 
                       AND LOWER(TRIM(COALESCE(`status_hunian`, ''))) IN ('aktif', 'check-in', 'checkin', 'menghuni', 'ditempati')"
                );
                if ($q_aktif_p && ($r_ap = mysqli_fetch_assoc($q_aktif_p)) && (int)$r_ap['jml'] > 0) {
                    $ada_penghuni_aktif = true;
                }
            }

            if ($ada_penghuni_aktif) {
                $_SESSION['flash_error_kamar'] = "Kamar '$nama_dihapus' tidak dapat dihapus karena masih ditempati oleh penyewa aktif.";
            } else {
                $q_fotos_del = @mysqli_query($conn, "SELECT * FROM `$tbl_foto_kamar` WHERE `id_kamar` = $id_kamar_hapus");
                if ($q_fotos_del) {
                    while ($fd = mysqli_fetch_assoc($q_fotos_del)) {
                        $fn = basename((string)($fd['nama_file'] ?? $fd['path_foto'] ?? ''));
                        if ($fn !== '' && file_exists('../uploads/' . $fn)) {
                            @unlink('../uploads/' . $fn);
                        }
                    }
                }

                $foto_utama_del = basename((string)($row_hapus[$col_foto_kamar] ?? ''));
                if ($foto_utama_del !== '' && file_exists('../uploads/' . $foto_utama_del)) {
                    @unlink('../uploads/' . $foto_utama_del);
                }

                @mysqli_query($conn, "DELETE FROM `$tbl_foto_kamar` WHERE `id_kamar` = $id_kamar_hapus");
                @mysqli_query($conn, "DELETE FROM `stok_kamar_log` WHERE `id_kamar` = $id_kamar_hapus");
                @mysqli_query($conn, "DELETE FROM `$tbl_kamar` WHERE `$pk_kamar` = $id_kamar_hapus AND `$fk_k_prop` = $selected_properti_id");

                $_SESSION['flash_sukses'] = "Tipe kamar '$nama_dihapus' berhasil dihapus secara permanen.";
            }
        }
    }

    header("Location: kelola_kos.php");
    exit;
}

// 3. Ambil daftar kamar khusus 1 properti milik mitra ini saja
$daftar_kamar = [];
if ($selected_properti_id > 0 && $fk_k_prop !== '' && !empty($cols_kamar)) {
    $q_kamar = @mysqli_query($conn, "SELECT * FROM `$tbl_kamar` WHERE `$fk_k_prop` = $selected_properti_id ORDER BY `$pk_kamar` DESC");
    if ($q_kamar) {
        while ($rk = mysqli_fetch_assoc($q_kamar)) {
            $daftar_kamar[] = $rk;
        }
    }
}

$allowed_kamar_ids = array_map(fn($k) => (int)($k[$pk_kamar] ?? 0), $daftar_kamar);
$req_kamar = isset($_REQUEST['id_kamar']) ? (int)$_REQUEST['id_kamar'] : 0;
if ($req_kamar > 0 && in_array($req_kamar, $allowed_kamar_ids, true)) {
    $id_kamar_aktif = $req_kamar;
} else {
    $id_kamar_aktif = !empty($allowed_kamar_ids) ? $allowed_kamar_ids[0] : 0;
}

$kamar_aktif = null;
foreach ($daftar_kamar as $k) {
    if ((int)($k[$pk_kamar] ?? 0) === $id_kamar_aktif) {
        $kamar_aktif = $k;
        break;
    }
}

// 4. Proses Update Data Kamar, Status & Galeri Foto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_kamar']) && $id_kamar_aktif > 0 && in_array($id_kamar_aktif, $allowed_kamar_ids, true)) {
    $nama_tipe  = mysqli_real_escape_string($conn, trim((string)($_POST['nama_tipe_kamar'] ?? '')));
    $harga_sewa = (int)preg_replace('/[^0-9]/', '', (string)($_POST['harga_sewa'] ?? '0'));
    $stok_kamar = max(0, (int)($_POST['stok_kamar'] ?? 0));
    $status_baru_k = $stok_kamar > 0 ? 'Tersedia' : 'Penuh';

    $fasilitas_arr = $_POST['fasilitas'] ?? [];
    if (!is_array($fasilitas_arr)) $fasilitas_arr = [];
    $fasilitas = mysqli_real_escape_string($conn, implode(', ', array_map('trim', $fasilitas_arr)));

    $set_parts = [
        "`$col_nama_kamar` = '$nama_tipe'",
        "`$col_harga_kamar` = $harga_sewa",
        "`$col_sisa_k` = $stok_kamar",
    ];
    if ($col_fas_kamar !== '') {
        $set_parts[] = "`$col_fas_kamar` = '$fasilitas'";
    }
    if ($col_status_kamar !== '') {
        $set_parts[] = "`$col_status_kamar` = '$status_baru_k'";
    }

    @mysqli_query(
        $conn,
        "UPDATE `$tbl_kamar` SET " . implode(', ', $set_parts) . " WHERE `$pk_kamar` = $id_kamar_aktif"
    );

    if (!empty($_POST['hapus_foto_ids']) && is_array($_POST['hapus_foto_ids'])) {
        foreach ($_POST['hapus_foto_ids'] as $id_f) {
            $id_f = (int)$id_f;
            $q_f_cek = @mysqli_query($conn, "SELECT * FROM `$tbl_foto_kamar` WHERE id_foto = $id_f AND id_kamar = $id_kamar_aktif LIMIT 1");
            if ($q_f_cek && ($row_f = mysqli_fetch_assoc($q_f_cek))) {
                $file_path = '../uploads/' . basename((string)($row_f['nama_file'] ?? ''));
                if ($file_path !== '../uploads/' && file_exists($file_path)) {
                    @unlink($file_path);
                }
                @mysqli_query($conn, "DELETE FROM `$tbl_foto_kamar` WHERE id_foto = $id_f AND id_kamar = $id_kamar_aktif");
            }
        }
    }

    if (isset($_FILES['galeri_foto']) && !empty($_FILES['galeri_foto']['name'][0])) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }

        $total_files = min(count($_FILES['galeri_foto']['name']), 5);
        for ($i = 0; $i < $total_files; $i++) {
            if (isset($_FILES['galeri_foto']['error'][$i]) && $_FILES['galeri_foto']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name  = $_FILES['galeri_foto']['tmp_name'][$i];
                $orig_name = $_FILES['galeri_foto']['name'][$i];
                $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $new_name = 'galeri_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                        $new_name_esc = mysqli_real_escape_string($conn, $new_name);
                        @mysqli_query($conn, "INSERT INTO `$tbl_foto_kamar` (id_kamar, nama_file) VALUES ($id_kamar_aktif, '$new_name_esc')");
                    }
                }
            }
        }
    }

    $_SESSION['flash_sukses'] = "Perubahan data & ketersediaan kamar berhasil disimpan.";
    header("Location: kelola_kos.php?id_kamar=" . $id_kamar_aktif);
    exit;
}

// Ambil daftar foto galeri dari database untuk kamar aktif
$galeri_foto = [];
if ($id_kamar_aktif > 0) {
    $q_galeri = @mysqli_query($conn, "SELECT * FROM `$tbl_foto_kamar` WHERE id_kamar = $id_kamar_aktif ORDER BY id_foto ASC");
    if ($q_galeri) {
        while ($gf = mysqli_fetch_assoc($q_galeri)) {
            $galeri_foto[] = $gf;
        }
    }
    if (empty($galeri_foto) && !empty($kamar_aktif[$col_foto_kamar] ?? '')) {
        $galeri_foto[] = ['id_foto' => 0, 'nama_file' => $kamar_aktif[$col_foto_kamar]];
    }
}

$fas_raw_val = ($kamar_aktif && $col_fas_kamar !== '' && !empty($kamar_aktif[$col_fas_kamar]))
    ? (string)$kamar_aktif[$col_fas_kamar]
    : '';
$fas_arr = $fas_raw_val !== '' ? array_map('trim', explode(',', $fas_raw_val)) : [];

$master_fasilitas = [
    'King Size (180 x 200)', 'Queen Size (160 x 200)', 'Single Bed',
    'KM Dalam (Shower & Duduk)', 'Kamar Mandi Luar',
    'AC Daikin 1/2 PK', 'Water Heater', 'Smart Doorlock',
    'Meja & Kursi Ergonomis', 'Lemari Pakaian', 'Springbed + Bantal',
    'Balkon Outdoor', 'Smart TV 40 Inch', 'WiFi Dedicated 100 Mbps',
    'Dapur Lengkap', 'Parkir Motor Kanopi', 'Mesin Cuci & Jemuran',
    'Air Minum RO', 'CCTV 24 Jam', 'Akses Gerbang 24 Jam'
];

$flash_sukses      = $_SESSION['flash_sukses'] ?? '';
$flash_error_kamar = $_SESSION['flash_error_kamar'] ?? '';
unset($_SESSION['flash_sukses'], $_SESSION['flash_error_kamar']);
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Kos &amp; Inventaris Kamar - KosLink Mitra</title>
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
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5">
                    <i class="fa-solid fa-house-chimney text-blue-600 text-xs mr-2.5"></i>
                    <div>
                        <p class="text-[9px] font-bold text-slate-400 uppercase leading-none">PROPERTI AKTIF (1 AKUN = 1 PROPERTI)</p>
                        <span class="text-xs font-bold text-slate-800"><?= htmlspecialchars($nama_properti_aktif) ?></span>
                    </div>
                </div>
                <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200/80 text-[11px] font-bold px-3 py-1.5 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> <?= $properti_aktif ? 'Aktif Terverifikasi' : 'Menunggu Properti' ?>
                </span>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right hidden sm:block">
                    <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_mitra) ?></p>
                    <p class="text-[11px] text-slate-400 font-medium">Mitra Terdaftar</p>
                </div>
            </div>
        </header>

        <!-- KONTEN UTAMA HALAMAN -->
        <main class="p-6 lg:p-8 max-w-[1200px] w-full mx-auto space-y-6">

            <?php if ($flash_sukses !== ''): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-3.5 rounded-2xl text-xs font-bold flex items-center gap-2.5 shadow-2xs">
                <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
                <span><?= htmlspecialchars($flash_sukses) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($flash_error_kamar !== ''): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 px-5 py-3.5 rounded-2xl text-xs font-bold flex items-center gap-2.5 shadow-2xs">
                <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
                <span><?= htmlspecialchars($flash_error_kamar) ?></span>
            </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Kelola Inventaris Kamar</h2>
                    <p class="text-xs text-slate-500 mt-1">Atur ketersediaan unit, fasilitas spesifik kamar, galeri foto, dan tarif sewa untuk properti <strong><?= htmlspecialchars($nama_properti_aktif) ?></strong>.</p>
                </div>
                <div>
                    <a href="tambah_kamar.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-4 py-2.5 rounded-xl transition shadow-sm inline-flex items-center gap-2">
                        <i class="fa-solid fa-plus"></i> Tambah Tipe Kamar
                    </a>
                </div>
            </div>

            <?php if ($kamar_aktif):
                $sisa_aktif   = max(0, (int)($kamar_aktif[$col_sisa_k] ?? 0));
                $terisi_aktif = (int)($terisi_by_kamar[$id_kamar_aktif] ?? 0);
                $total_aktif  = $sisa_aktif + $terisi_aktif;
                $st_kamar_raw = strtolower(trim((string)($kamar_aktif[$col_status_kamar] ?? ($sisa_aktif > 0 ? 'tersedia' : 'penuh'))));
                $is_draft_k   = ($st_kamar_raw === 'draft');
                $is_tersedia  = (!$is_draft_k && $sisa_aktif > 0);
            ?>
                <!-- TAB PILIHAN TIPE KAMAR DENGAN INFO SISA KAMAR -->
                <div class="flex flex-wrap items-center gap-2">
                    <?php foreach ($daftar_kamar as $item_k):
                        $kid     = (int)($item_k[$pk_kamar] ?? 0);
                        $sisa_it = max(0, (int)($item_k[$col_sisa_k] ?? 0));
                        $is_sel  = ($kid === $id_kamar_aktif);
                    ?>
                        <a href="kelola_kos.php?id_kamar=<?= $kid ?>"
                           class="px-3.5 py-2 rounded-xl text-xs font-bold border transition inline-flex items-center gap-2 <?= $is_sel ? 'bg-blue-600 text-white border-blue-600 shadow-xs' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' ?>">
                            <span><?= htmlspecialchars((string)($item_k[$col_nama_kamar] ?? 'Kamar')) ?></span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-extrabold <?= $is_sel ? 'bg-white/20 text-white' : ($sisa_it > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700') ?>">
                                <?= $sisa_it > 0 ? "Sisa {$sisa_it}" : 'Penuh' ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- PRATINJAU GALERI FOTO & FASILITAS KAMAR -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h3 class="text-base font-extrabold text-slate-900">
                                Pratinjau Kamar Aktif: <?= htmlspecialchars((string)($kamar_aktif[$col_nama_kamar] ?? 'Kamar')) ?>
                            </h3>

                            <!-- Badge Status Ketersediaan Kamar (Tersedia / Penuh / Draft) -->
                            <?php if ($is_draft_k): ?>
                                <span class="inline-flex items-center gap-1.5 bg-slate-100 text-slate-600 border border-slate-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Status: Draft
                                </span>
                            <?php elseif ($is_tersedia): ?>
                                <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Status: Tersedia (<?= $sisa_aktif ?> Unit)
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Status: Penuh (0 Unit Tersedia)
                                </span>
                            <?php endif; ?>

                            <!-- Badge Status Validasi Admin -->
                            <?php
                            $st_val = strtolower(trim((string)($kamar_aktif['status_validasi'] ?? 'disetujui')));
                            if ($st_val === 'pending'): ?>
                                <span class="bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">Menunggu Verifikasi Admin</span>
                            <?php elseif ($st_val === 'revisi'): ?>
                                <span class="bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">Perlu Revisi Admin</span>
                            <?php else: ?>
                                <span class="bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-extrabold px-2.5 py-0.5 rounded-full">Terverifikasi Admin</span>
                            <?php endif; ?>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" onclick="openModalEdit()" class="text-xs font-bold text-blue-600 hover:bg-blue-100 bg-blue-50 px-3.5 py-2 rounded-xl transition inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-pen-to-square"></i> Ubah Fasilitas, Tarif &amp; Galeri Foto
                            </button>
                            <button type="button"
                                    onclick="openModalHapus(<?= (int)$id_kamar_aktif ?>, <?= htmlspecialchars(json_encode((string)($kamar_aktif[$col_nama_kamar] ?? 'Kamar')), ENT_QUOTES, 'UTF-8') ?>)"
                                    class="text-xs font-bold text-rose-600 hover:bg-rose-100 bg-rose-50 border border-rose-200/80 px-3.5 py-2 rounded-xl transition inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-trash-can"></i> Hapus Kamar
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                        <!-- GALERI FOTO MULTI-SLOT -->
                        <div class="lg:col-span-5 space-y-3">
                            <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Galeri Foto Kamar (<?= count($galeri_foto) ?> Foto)</h4>
                            
                            <div class="h-52 rounded-2xl overflow-hidden bg-slate-100 border border-slate-200 relative shadow-xs">
                                <?php
                                $img_utama = !empty($galeri_foto)
                                    ? '../uploads/' . basename((string)$galeri_foto[0]['nama_file'])
                                    : 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=800&q=80';
                                ?>
                                <img src="<?= htmlspecialchars($img_utama) ?>"
                                     onerror="this.src='https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=800&q=80'"
                                     alt="Foto Utama Kamar"
                                     class="w-full h-full object-cover">
                                <div class="absolute bottom-3 left-3 bg-slate-900/70 backdrop-blur-md text-white text-[11px] font-bold px-3 py-1 rounded-lg">
                                    Sudut Kamar Utama
                                </div>
                            </div>

                            <?php if (count($galeri_foto) > 1): ?>
                                <div class="grid grid-cols-3 gap-2">
                                    <?php for ($i = 1; $i < count($galeri_foto) && $i < 4; $i++): ?>
                                        <div class="h-20 rounded-xl overflow-hidden bg-slate-100 border border-slate-200 relative">
                                            <img src="../uploads/<?= htmlspecialchars(basename((string)$galeri_foto[$i]['nama_file'])) ?>" alt="Foto Sudut" class="w-full h-full object-cover">
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- DAFTAR FASILITAS & RINGKASAN KETERSEDIAAN REAL-TIME -->
                        <div class="lg:col-span-7 space-y-4">
                            <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Kelengkapan Fasilitas Kamar &amp; Bersama</h4>
                            <div class="flex flex-wrap gap-2">
                                <?php
                                $ada_fas = false;
                                foreach ($fas_arr as $f):
                                    if (trim($f) !== ''):
                                        $ada_fas = true;
                                ?>
                                    <span class="bg-slate-50 border border-slate-200 text-slate-700 text-xs font-bold px-3 py-1.5 rounded-xl inline-flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-check text-blue-600"></i> <?= htmlspecialchars(trim($f)) ?>
                                    </span>
                                <?php
                                    endif;
                                endforeach;
                                if (!$ada_fas):
                                ?>
                                    <span class="text-xs text-slate-400 italic">Belum ada fasilitas yang dipilih.</span>
                                <?php endif; ?>
                            </div>

                            <!-- Kartu Ringkasan Ketersediaan & Tarif Real-Time -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-4 border-t border-slate-100 mt-4">
                                <div class="p-3 rounded-2xl bg-slate-50 border border-slate-200/70">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Tarif Sewa / Bln</span>
                                    <span class="text-sm font-extrabold text-slate-900 mt-0.5 block">Rp <?= number_format((int)($kamar_aktif[$col_harga_kamar] ?? 0), 0, ',', '.') ?></span>
                                </div>
                                <div class="p-3 rounded-2xl <?= $is_tersedia ? 'bg-emerald-50/70 border-emerald-200' : 'bg-rose-50/70 border-rose-200' ?> border">
                                    <span class="text-[10px] font-bold text-slate-500 uppercase block">Sisa Kamar</span>
                                    <span class="text-sm font-extrabold <?= $is_tersedia ? 'text-emerald-700' : 'text-rose-700' ?> mt-0.5 block">
                                        <?= $sisa_aktif ?> Unit Tersedia
                                    </span>
                                </div>
                                <div class="p-3 rounded-2xl bg-blue-50/60 border border-blue-200/70">
                                    <span class="text-[10px] font-bold text-slate-500 uppercase block">Unit Terisi</span>
                                    <span class="text-sm font-extrabold text-blue-700 mt-0.5 block"><?= $terisi_aktif ?> Penghuni</span>
                                </div>
                                <div class="p-3 rounded-2xl bg-slate-50 border border-slate-200/70">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Total Kapasitas</span>
                                    <span class="text-sm font-extrabold text-slate-800 mt-0.5 block"><?= $total_aktif ?> Unit Kamar</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white border border-slate-200/80 rounded-3xl p-10 text-center space-y-3 shadow-2xs">
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto text-lg">
                        <i class="fa-solid fa-door-closed"></i>
                    </div>
                    <h3 class="text-base font-extrabold text-slate-900">Belum Ada Data Kamar</h3>
                    <p class="text-xs text-slate-500 max-w-md mx-auto">
                        Akun mitra ini belum memiliki kamar yang terdaftar pada propertinya. Silakan tambahkan tipe kamar terlebih dahulu.
                    </p>
                    <div class="pt-2">
                        <a href="tambah_kamar.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-5 py-2.5 rounded-xl transition inline-flex items-center gap-2 shadow-sm">
                            <i class="fa-solid fa-plus"></i> Tambah Kamar Sekarang
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- MODAL POP-UP KONFIRMASI HAPUS KAMAR -->
    <div id="modal-hapus-kamar" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-7 shadow-2xl border border-slate-100 space-y-5 text-center">
            <div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 border border-rose-200/80 flex items-center justify-center mx-auto text-2xl">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <div class="space-y-2">
                <h3 class="text-lg font-extrabold text-slate-900">Apakah Yakin Ingin Menghapus Kamar Ini?</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Anda akan menghapus tipe kamar <strong id="hapus-nama-kamar" class="text-slate-900">-</strong> beserta seluruh galeri fotonya. Tindakan ini bersifat permanen dan tidak dapat dibatalkan.
                </p>
            </div>

            <form method="POST" action="kelola_kos.php" class="flex items-center justify-center gap-3 pt-2 text-xs">
                <input type="hidden" name="hapus_kamar" value="1">
                <input type="hidden" name="id_kamar_hapus" id="hapus-id-kamar" value="">

                <button type="button" onclick="closeModalHapus()" class="flex-1 py-3 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">
                    Batal
                </button>
                <button type="submit" class="flex-1 py-3 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold shadow-sm shadow-rose-200 transition inline-flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-trash-can"></i> Ya, Hapus Kamar
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL UBAH FASILITAS, TARIF & KELOLA MULTI-FOTO -->
    <?php if ($kamar_aktif): ?>
    <div id="modal-edit" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-xl space-y-5 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-extrabold text-slate-900">Ubah Fasilitas, Tarif &amp; Kelola Galeri Foto (Maks. 5 Foto)</h3>
                <button type="button" onclick="closeModalEdit()" class="text-slate-400 hover:text-slate-700 font-bold text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="kelola_kos.php?id_kamar=<?= $id_kamar_aktif ?>" enctype="multipart/form-data" class="space-y-4 text-xs">
                <input type="hidden" name="update_kamar" value="1">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nama Tipe Kamar</label>
                    <input type="text" name="nama_tipe_kamar" value="<?= htmlspecialchars((string)($kamar_aktif[$col_nama_kamar] ?? '')) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold text-slate-800">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Tarif Sewa / Bulan (Rp)</label>
                        <input type="number" name="harga_sewa" value="<?= (int)($kamar_aktif[$col_harga_kamar] ?? 0) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold text-slate-800">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Sisa Unit Kosong Siap Huni</label>
                        <input type="number" name="stok_kamar" min="0" value="<?= (int)($kamar_aktif[$col_sisa_k] ?? 0) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold text-slate-800">
                    </div>
                </div>

                <!-- PILIHAN FASILITAS INTERAKTIF -->
                <div>
                    <label class="block font-bold text-slate-700 mb-2">Pilih Kelengkapan Fasilitas Kamar &amp; Bersama</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 bg-slate-50 border border-slate-200 p-3 rounded-2xl max-h-40 overflow-y-auto">
                        <?php foreach ($master_fasilitas as $mf):
                            $is_checked = in_array($mf, $fas_arr, true);
                        ?>
                            <label class="flex items-center gap-2 p-2 rounded-xl hover:bg-white cursor-pointer transition select-none">
                                <input type="checkbox" name="fasilitas[]" value="<?= htmlspecialchars($mf) ?>" <?= $is_checked ? 'checked' : '' ?> class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4">
                                <span class="font-semibold text-slate-700 text-[11px]"><?= htmlspecialchars($mf) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- KELOLA GALERI FOTO -->
                <div class="space-y-3 pt-3 border-t border-slate-100">
                    <label class="block font-bold text-slate-700">Kelola Galeri Foto Kamar (Centang foto yang ingin dihapus)</label>
                    
                    <?php if (count($galeri_foto) > 0): ?>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            <?php foreach ($galeri_foto as $gf): if ((int)($gf['id_foto'] ?? 0) > 0): ?>
                                <div class="bg-slate-50 border border-slate-200 rounded-xl p-2 relative space-y-1 text-center">
                                    <div class="h-16 rounded-lg overflow-hidden bg-slate-200">
                                        <img src="../uploads/<?= htmlspecialchars(basename((string)$gf['nama_file'])) ?>" class="w-full h-full object-cover">
                                    </div>
                                    <label class="inline-flex items-center gap-1 text-[10px] text-rose-600 font-bold cursor-pointer">
                                        <input type="checkbox" name="hapus_foto_ids[]" value="<?= (int)$gf['id_foto'] ?>" class="rounded text-rose-600"> Hapus
                                    </label>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-slate-400 italic">Belum ada foto galeri tersimpan.</p>
                    <?php endif; ?>

                    <div class="pt-2">
                        <label class="block font-semibold text-slate-600 mb-1">Unggah Foto Tambahan Baru (Maks. 5 foto)</label>
                        <input type="file" name="galeri_foto[]" multiple accept="image/*" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <p class="text-[10px] text-slate-400 mt-1">Format yang didukung: JPG, JPEG, PNG, WEBP.</p>
                    </div>
                </div>

                <div class="flex justify-end gap-2.5 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeModalEdit()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition">Batal</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl transition shadow-sm">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openModalEdit() {
            const el = document.getElementById('modal-edit');
            if (el) el.classList.remove('hidden');
        }
        function closeModalEdit() {
            const el = document.getElementById('modal-edit');
            if (el) el.classList.add('hidden');
        }

        function openModalHapus(idKamar, namaKamar) {
            document.getElementById('hapus-id-kamar').value = idKamar;
            document.getElementById('hapus-nama-kamar').innerText = '"' + namaKamar + '"';
            const modal = document.getElementById('modal-hapus-kamar');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }
        function closeModalHapus() {
            const modal = document.getElementById('modal-hapus-kamar');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }
    </script>
</body>
</html>