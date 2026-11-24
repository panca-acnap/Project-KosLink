<?php
require 'koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_OFF);

$id_url = isset($_GET['id']) ? (int)$_GET['id'] : 1;

// Proteksi wajib login sebagai pencari/penyewa sebelum mengakses form pemesanan
if (!isset($_SESSION['role']) || $_SESSION['role'] === 'mitra') {
    $target_redirect = 'sewa.php?id=' . $id_url;
    $_SESSION['redirect_after_login'] = $target_redirect;
    $_SESSION['error_login'] = 'Silakan masuk ke akun pencari kos terlebih dahulu untuk melanjutkan pemesanan kamar.';
    header("Location: auth/login.php?redirect=" . urlencode($target_redirect));
    exit;
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

// Auto-migrasi kolom NIK pada tabel penyewa dan pengguna
$cols_penyewa = getTableColumns($conn, 'penyewa');
if (!empty($cols_penyewa) && !in_array('nik', $cols_penyewa, true)) {
    @mysqli_query($conn, "ALTER TABLE `penyewa` ADD COLUMN `nik` VARCHAR(20) NULL AFTER `nama_penyewa`");
    $cols_penyewa[] = 'nik';
}
$cols_pengguna = getTableColumns($conn, 'pengguna');
if (!empty($cols_pengguna) && !in_array('nik', $cols_pengguna, true)) {
    @mysqli_query($conn, "ALTER TABLE `pengguna` ADD COLUMN `nik` VARCHAR(20) NULL");
    $cols_pengguna[] = 'nik';
}

$id_pengguna = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? $_SESSION['id_pencari'] ?? $_SESSION['id'] ?? 0);

// Ambil profil akun pengguna dari database untuk pre-fill NIK & No. HP
$akun_db = [];
if ($id_pengguna > 0 && !empty($cols_pengguna)) {
    $q_ak = @mysqli_query($conn, "SELECT * FROM `pengguna` WHERE `id_pengguna` = $id_pengguna LIMIT 1");
    if ($q_ak && mysqli_num_rows($q_ak) > 0) {
        $akun_db = mysqli_fetch_assoc($q_ak);
    }
}

// Handler aksi: Hapus pengajuan lama (Pending) & langsung lanjutkan ke pemesanan kamar baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_hapus_pending'])) {
    $id_pending_lama = (int)($_POST['id_pending_lama'] ?? 0);
    if ($id_pending_lama > 0 && $id_pengguna > 0) {
        @mysqli_query(
            $conn,
            "DELETE FROM `penyewa` 
             WHERE `id_penyewa` = $id_pending_lama 
               AND `id_pengguna` = $id_pengguna 
               AND LOWER(TRIM(COALESCE(`status_hunian`, ''))) IN ('', 'pending', 'menunggu')
               AND LOWER(TRIM(COALESCE(`status_bayar`, ''))) NOT IN ('lunas', 'paid', 'terbayar')"
        );
    }
    header("Location: sewa.php?id=" . $id_url);
    exit;
}

// Cek apakah akun sudah memiliki 1 kos AKTIF atau pengajuan PENDING yang belum dibayar
function cekStatusHunianPenyewa($conn, $id_pengguna, $nik_input = '', $hp_input = '') {
    $hasil = ['aktif' => null, 'pending' => null];
    $conds = [];
    if ($id_pengguna > 0) {
        $conds[] = "`id_pengguna` = $id_pengguna";
    }
    $nik_clean = preg_replace('/[^0-9]/', '', $nik_input);
    if ($nik_clean !== '') {
        $nik_esc = mysqli_real_escape_string($conn, $nik_clean);
        $conds[] = "`nik` = '$nik_esc'";
    }
    $hp_clean = preg_replace('/[^0-9]/', '', $hp_input);
    if ($hp_clean !== '') {
        $hp_esc = mysqli_real_escape_string($conn, $hp_clean);
        $conds[] = "REPLACE(REPLACE(REPLACE(`no_hp`, '-', ''), ' ', ''), '+', '') = '$hp_esc'";
    }
    if (empty($conds)) return $hasil;

    $where = '(' . implode(' OR ', $conds) . ')';
    $q = @mysqli_query($conn, "SELECT * FROM `penyewa` WHERE $where ORDER BY `id_penyewa` DESC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $st_h = strtolower(trim((string)($r['status_hunian'] ?? '')));
            $st_b = strtolower(trim((string)($r['status_bayar'] ?? '')));
            $is_keluar = in_array($st_h, ['keluar', 'selesai', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif'], true);
            if ($is_keluar) continue;

            if ($hasil['aktif'] === null && (in_array($st_h, ['aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true) || in_array($st_b, ['lunas', 'paid', 'terbayar', 'berhasil'], true))) {
                $hasil['aktif'] = $r;
            } elseif ($hasil['pending'] === null && (int)$r['id_pengguna'] === $id_pengguna && in_array($st_h, ['', 'pending', 'menunggu'], true)) {
                $hasil['pending'] = $r;
            }
        }
    }
    return $hasil;
}

$status_awal_user = cekStatusHunianPenyewa(
    $conn,
    $id_pengguna,
    $akun_db['nik'] ?? ($_SESSION['nik'] ?? ''),
    $akun_db['no_hp'] ?? ($_SESSION['no_hp'] ?? '')
);

$kamar = null;
$properti = null;

$cols_kamar = getTableColumns($conn, 'kamar');
$pk_kamar   = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : 'id';
$cols_prop  = getTableColumns($conn, 'properti_kos');
$pk_prop    = in_array('id_properti', $cols_prop, true) ? 'id_properti' : 'id_kos';
$fk_kamar   = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : 'id_kos';

$q_k = @mysqli_query($conn, "SELECT * FROM kamar WHERE `$pk_kamar` = $id_url LIMIT 1");
if ($q_k && mysqli_num_rows($q_k) > 0) {
    $kamar = mysqli_fetch_assoc($q_k);
    $id_prop = (int)($kamar[$fk_kamar] ?? 1);

    $q_p = @mysqli_query($conn, "SELECT * FROM properti_kos WHERE `$pk_prop` = $id_prop LIMIT 1");
    if ($q_p && mysqli_num_rows($q_p) > 0) {
        $properti = mysqli_fetch_assoc($q_p);
    }
}

$col_nama_kos   = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : 'nama_properti';
$col_nama_kamar = in_array('nama_kamar', $cols_kamar, true) ? 'nama_kamar' : (in_array('nomor_kamar', $cols_kamar, true) ? 'nomor_kamar' : 'tipe_kamar');
$col_harga      = in_array('harga_bulan', $cols_kamar, true) ? 'harga_bulan' : (in_array('harga_bulanan', $cols_kamar, true) ? 'harga_bulanan' : (in_array('harga_sewa', $cols_kamar, true) ? 'harga_sewa' : 'harga'));
$col_foto       = in_array('foto_kamar', $cols_kamar, true) ? 'foto_kamar' : (in_array('foto', $cols_kamar, true) ? 'foto' : '');

$nama_kos  = $properti[$col_nama_kos] ?? 'Kos Udayana Exclusive Residence';
$alamat    = $properti['alamat'] ?? $properti['lokasi'] ?? 'Jl. Raya Kampus & Pusat Bisnis No. 24, Jimbaran, Badung, Bali';
$nama_unit = $kamar[$col_nama_kamar] ?? 'Deluxe Room A (Lantai 2)';
$harga_bln = (int)($kamar[$col_harga] ?? 1750000);

$galeri_kamar = [];
$id_kamar_db  = (int)($kamar[$pk_kamar] ?? $id_url);

if (count(getTableColumns($conn, 'foto_kamar')) > 0) {
    $q_foto = @mysqli_query($conn, "SELECT nama_file FROM foto_kamar WHERE id_kamar = $id_kamar_db ORDER BY id_foto ASC LIMIT 5");
    if ($q_foto && mysqli_num_rows($q_foto) > 0) {
        while ($rf = mysqli_fetch_assoc($q_foto)) {
            if (!empty($rf['nama_file'])) {
                $galeri_kamar[] = 'uploads/' . basename($rf['nama_file']);
            }
        }
    }
}

if (empty($galeri_kamar) && $col_foto !== '') {
    $foto_kamar_db = $kamar[$col_foto] ?? '';
    if (!empty($foto_kamar_db)) {
        $decoded = json_decode($foto_kamar_db, true);
        if (is_array($decoded)) {
            foreach (array_slice($decoded, 0, 5) as $f_item) {
                if (!empty($f_item)) {
                    $galeri_kamar[] = 'uploads/' . basename((string)$f_item);
                }
            }
        } else {
            foreach (array_slice(explode(',', $foto_kamar_db), 0, 5) as $f_item) {
                $f_clean = trim($f_item);
                if ($f_clean !== '') {
                    $galeri_kamar[] = 'uploads/' . basename($f_clean);
                }
            }
        }
    }
}

$fallback_img = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=800&q=80';
if (empty($galeri_kamar)) {
    $galeri_kamar[] = $fallback_img;
}
$galeri_kamar = array_slice($galeri_kamar, 0, 5);
$foto_unit    = $galeri_kamar[0];

$saved_data     = $_SESSION['pending_booking'] ?? [];
$form_tanggal   = $_POST['tanggal_masuk'] ?? $saved_data['tanggal_masuk'] ?? date('Y-m-d');
$form_durasi    = $_POST['durasi'] ?? $saved_data['durasi'] ?? '1 Bulan';
$form_nama      = $_POST['nama_lengkap'] ?? $saved_data['nama_lengkap'] ?? ($akun_db['nama_lengkap'] ?? $_SESSION['nama'] ?? '');
$form_nik       = $_POST['nik'] ?? ($akun_db['nik'] ?? $_SESSION['nik'] ?? '');
$form_pekerjaan = $_POST['pekerjaan'] ?? $saved_data['pekerjaan'] ?? '';
$form_nohp      = $_POST['no_hp'] ?? $saved_data['no_hp'] ?? ($akun_db['no_hp'] ?? $_SESSION['no_hp'] ?? '');
$form_email     = $_POST['email'] ?? $saved_data['email'] ?? ($akun_db['email'] ?? $_SESSION['email'] ?? '');

$multiplier_awal = 1;
if ($form_durasi === 'Per 3 Bulan') $multiplier_awal = 3;
elseif ($form_durasi === '6 Bulan') $multiplier_awal = 6;
elseif ($form_durasi === 'Tahunan') $multiplier_awal = 12;
$total_harga_awal = ($harga_bln * $multiplier_awal) + 500000;

$error_sewa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['aksi_hapus_pending'])) {
    $nama_raw      = trim($_POST['nama_lengkap'] ?? 'Pengguna');
    $nik_raw       = preg_replace('/[^0-9]/', '', $_POST['nik'] ?? '');
    $no_hp_raw     = trim($_POST['no_hp'] ?? '');
    $pekerjaan_raw = trim($_POST['pekerjaan'] ?? '');
    $tgl_raw       = trim($_POST['tanggal_masuk'] ?? date('Y-m-d'));
    $durasi_raw    = trim($_POST['durasi'] ?? '1 Bulan');

    if (strlen($nik_raw) !== 16) {
        $error_sewa = 'NIK (Nomor Induk Kependudukan) wajib diisi tepat 16 digit angka sesuai KTP.';
    } else {
        $cek_post = cekStatusHunianPenyewa($conn, $id_pengguna, $nik_raw, $no_hp_raw);
        if ($cek_post['aktif'] !== null) {
            $status_awal_user = $cek_post;
            $error_sewa = 'Pengajuan ditolak: NIK / Nomor WhatsApp / Akun Anda sudah terdaftar aktif pada hunian ' . ($cek_post['aktif']['no_kamar'] ?? 'kos lain') . '. Maksimal hanya 1 kos aktif per penyewa.';
        } elseif ($cek_post['pending'] !== null) {
            $status_awal_user = $cek_post;
            $error_sewa = 'Anda masih memiliki pengajuan pembayaran yang belum selesai untuk kamar "' . ($cek_post['pending']['no_kamar'] ?? 'sebelumnya') . '".';
        } else {
            $nama_penyewa  = mysqli_real_escape_string($conn, $nama_raw);
            $nik_esc       = mysqli_real_escape_string($conn, $nik_raw);
            $no_hp         = mysqli_real_escape_string($conn, $no_hp_raw);
            $pekerjaan     = mysqli_real_escape_string($conn, $pekerjaan_raw);
            $tanggal_masuk = mysqli_real_escape_string($conn, $tgl_raw);
            $durasi        = mysqli_real_escape_string($conn, $durasi_raw);

            $multiplier = 1;
            if ($durasi_raw === 'Per 3 Bulan') $multiplier = 3;
            elseif ($durasi_raw === '6 Bulan') $multiplier = 6;
            elseif ($durasi_raw === 'Tahunan') $multiplier = 12;

            $total_nominal_sewa = ($harga_bln * $multiplier) + 500000;
            $id_prop_db   = (int)($properti[$pk_prop] ?? 1);
            $jatuh_tempo  = date('Y-m-d', strtotime($tanggal_masuk . ' + ' . ($multiplier * 30) . ' days'));

            // Simpan NIK ke profil pengguna agar tersinkronisasi
            if ($id_pengguna > 0) {
                @mysqli_query($conn, "UPDATE `pengguna` SET `nik` = '$nik_esc' WHERE `id_pengguna` = $id_pengguna");
                $_SESSION['nik'] = $nik_raw;
            }

            $sql_insert = "INSERT INTO penyewa (id_pengguna, id_properti, id_kamar, no_kamar, nama_penyewa, nik, no_hp, pekerjaan, skema_kontrak, tanggal_masuk, jatuh_tempo, nominal_sewa, status_bayar, status_escrow, status_hunian) 
                           VALUES ($id_pengguna, $id_prop_db, $id_url, '$nama_unit', '$nama_penyewa', '$nik_esc', '$no_hp', '$pekerjaan', '$durasi', '$tanggal_masuk', '$jatuh_tempo', $total_nominal_sewa, 'Menunggu Pembayaran', 'Menunggu Dana', 'Pending')";

            @mysqli_query($conn, $sql_insert);
            $id_sewa_baru = (int)mysqli_insert_id($conn);

            unset($_SESSION['pending_booking'], $_SESSION['redirect_after_login']);

            header("Location: penyewa/bayar.php?id_sewa=" . $id_sewa_baru);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemesanan &amp; Data Sewa - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- NAVBAR TOP -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                <a href="index.php" class="flex items-center gap-2.5">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                        <i class="fa-solid fa-building text-sm"></i>
                    </div>
                    <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
                </a>
                <a href="detail_kamar.php?id=<?= $id_url ?>" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Detail Kamar
                </a>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold text-slate-700">Halo, <?= htmlspecialchars($_SESSION['nama'] ?? 'Pengguna') ?></span>
                <a href="auth/logout.php" class="text-xs font-bold text-rose-600 px-3 py-2 transition">Keluar</a>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 flex-1">

        <?php if ($status_awal_user['aktif'] !== null): ?>
        <!-- BLOKIR JIKA SUDAH MEMILIKI 1 KOS AKTIF -->
        <div class="max-w-3xl mx-auto bg-white border border-rose-200 rounded-3xl p-8 shadow-sm text-center space-y-4">
            <div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center text-2xl mx-auto">
                <i class="fa-solid fa-ban"></i>
            </div>
            <h2 class="text-xl font-extrabold text-slate-900">Batas Maksimal 1 Kos Aktif Terpenuhi</h2>
            <p class="text-xs text-slate-600 max-w-lg mx-auto leading-relaxed">
                Akun dan data pribadi Anda saat ini sudah terdaftar sebagai penghuni aktif di <strong><?= htmlspecialchars($status_awal_user['aktif']['no_kamar'] ?? 'kamar kos') ?></strong>. Satu penyewa hanya diperbolehkan menyewa maksimal 1 kos aktif.
            </p>
            <div class="pt-2 flex flex-wrap justify-center gap-3">
                <a href="penyewa/dashboard.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-5 py-3 rounded-xl transition">
                    Buka Dashboard Kos Aktif Saya
                </a>
                <a href="index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-5 py-3 rounded-xl transition">
                    Kembali ke Beranda
                </a>
            </div>
        </div>

        <?php elseif ($status_awal_user['pending'] !== null): ?>
        <!-- KONFIRMASI JIKA ADA PEMBAYARAN PENDING YANG BELUM SELESAI -->
        <div class="max-w-3xl mx-auto bg-white border border-amber-200 rounded-3xl p-8 shadow-sm space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-extrabold uppercase tracking-wider text-amber-700">TRANSAKSI MENUNGGU PEMBAYARAN</span>
                    <h2 class="text-lg sm:text-xl font-extrabold text-slate-900">
                        Pembayaran untuk "<?= htmlspecialchars($status_awal_user['pending']['no_kamar'] ?? 'Kamar Sebelumnya') ?>" belum selesai
                    </h2>
                    <p class="text-xs text-slate-600 leading-relaxed">
                        Sesuai aturan 1 penyewa maksimal 1 kos, Anda tidak dapat memiliki dua pengajuan sewa sekaligus. Silakan lanjutkan pembayaran lama Anda atau hapus pengajuan lama untuk menyewa <strong><?= htmlspecialchars($nama_unit) ?></strong>.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                <a href="penyewa/bayar.php?id_sewa=<?= (int)$status_awal_user['pending']['id_penyewa'] ?>" class="py-3.5 px-4 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold text-center shadow-sm transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-wallet"></i> Lanjutkan Pembayaran Lama
                </a>
                <form method="POST">
                    <input type="hidden" name="aksi_hapus_pending" value="1">
                    <input type="hidden" name="id_pending_lama" value="<?= (int)$status_awal_user['pending']['id_penyewa'] ?>">
                    <button type="submit" class="w-full py-3.5 px-4 rounded-2xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-extrabold transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-trash-can"></i> Lanjutkan Pembayaran Baru (Hapus Yang Lama)
                    </button>
                </form>
            </div>
        </div>

        <?php else: ?>

        <?php if ($error_sewa !== ''): ?>
            <div class="max-w-6xl mx-auto bg-rose-50 border border-rose-200 text-rose-800 px-5 py-3.5 rounded-2xl text-xs font-bold flex items-center gap-2.5">
                <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
                <span><?= htmlspecialchars($error_sewa) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start max-w-6xl mx-auto">
            
            <!-- KIRI: DETAIL PROPERTI & FORM -->
            <div class="lg:col-span-8 space-y-6">

                <!-- KARTU INFO KOS & GALERI FOTO -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-5 shadow-sm flex flex-col sm:flex-row gap-5 items-start">
                    <div class="w-full sm:w-52 flex-shrink-0 space-y-2">
                        <div class="w-full h-36 rounded-2xl overflow-hidden bg-slate-100 border border-slate-200/70">
                            <img id="foto-utama-sewa"
                                 src="<?= htmlspecialchars($foto_unit) ?>"
                                 onerror="this.src='<?= htmlspecialchars($fallback_img) ?>'"
                                 alt="Foto Kamar"
                                 class="w-full h-full object-cover transition">
                        </div>

                        <?php if (count($galeri_kamar) > 1): ?>
                            <div class="grid grid-cols-5 gap-1.5">
                                <?php foreach ($galeri_kamar as $idx => $url_foto): ?>
                                    <button type="button"
                                            onclick="gantiFotoUtama('<?= htmlspecialchars($url_foto, ENT_QUOTES) ?>', this)"
                                            class="thumb-foto h-10 rounded-lg overflow-hidden border-2 transition <?= $idx === 0 ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200 opacity-75 hover:opacity-100' ?>"
                                            title="Lihat Foto <?= $idx + 1 ?>">
                                        <img src="<?= htmlspecialchars($url_foto) ?>"
                                             onerror="this.src='<?= htmlspecialchars($fallback_img) ?>'"
                                             alt="Thumbnail <?= $idx + 1 ?>"
                                             class="w-full h-full object-cover">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-2 w-full text-xs">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="bg-blue-50 text-blue-700 font-extrabold px-2.5 py-1 rounded-md">Terverifikasi KosLink</span>
                            <span class="bg-slate-100 text-slate-600 font-bold px-2.5 py-1 rounded-md">
                                <i class="fa-regular fa-images mr-1"></i> <?= count($galeri_kamar) ?>/5 Foto
                            </span>
                        </div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900"><?= htmlspecialchars($nama_kos) ?></h2>
                        <p class="text-slate-500"><i class="fa-solid fa-location-dot text-rose-500 mr-1"></i> <?= htmlspecialchars($alamat) ?></p>
                    </div>
                </div>

                <!-- PERIODE & DURASI SEWA -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                    <div class="flex items-center gap-2.5 border-b border-slate-100 pb-3">
                        <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                            <i class="fa-regular fa-calendar"></i>
                        </div>
                        <h3 class="text-sm font-extrabold text-slate-900">Periode &amp; Durasi Sewa</h3>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-700">Tanggal Mulai Check-in *</label>
                            <input type="date" name="tanggal_masuk" value="<?= htmlspecialchars($form_tanggal) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-700">Tipe Unit Terpilih *</label>
                            <div class="p-2.5 bg-blue-50/70 border border-blue-200 rounded-xl flex items-center justify-between">
                                <span class="font-extrabold text-blue-900"><i class="fa-solid fa-door-open mr-1"></i> <?= htmlspecialchars($nama_unit) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2 pt-1">
                        <label class="font-extrabold text-slate-700 block">Pilih Durasi Sewa</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <label class="durasi-label p-3 rounded-2xl border-2 <?= ($form_durasi === '1 Bulan') ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200 bg-white' ?> hover:border-slate-300 cursor-pointer text-center block transition has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50">
                                <input type="radio" name="durasi" value="1 Bulan" data-multiplier="1" <?= ($form_durasi === '1 Bulan') ? 'checked' : '' ?> class="durasi-radio sr-only peer" onchange="updateHargaSewa()">
                                <span class="font-extrabold <?= ($form_durasi === '1 Bulan') ? 'text-blue-900' : 'text-slate-800' ?> peer-checked:text-blue-900 block text-xs judul-durasi">1 Bulan</span>
                                <span class="text-[10px] text-slate-400 peer-checked:text-slate-600 font-semibold">Standar</span>
                            </label>

                            <label class="durasi-label p-3 rounded-2xl border-2 <?= ($form_durasi === 'Per 3 Bulan') ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200 bg-white' ?> hover:border-slate-300 cursor-pointer text-center block transition has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50">
                                <input type="radio" name="durasi" value="Per 3 Bulan" data-multiplier="3" <?= ($form_durasi === 'Per 3 Bulan') ? 'checked' : '' ?> class="durasi-radio sr-only peer" onchange="updateHargaSewa()">
                                <span class="font-extrabold <?= ($form_durasi === 'Per 3 Bulan') ? 'text-blue-900' : 'text-slate-800' ?> peer-checked:text-blue-900 block text-xs judul-durasi">Per 3 Bulan</span>
                                <span class="text-[10px] text-slate-400 peer-checked:text-slate-600 font-semibold">3× Bulan</span>
                            </label>

                            <label class="durasi-label p-3 rounded-2xl border-2 <?= ($form_durasi === '6 Bulan') ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200 bg-white' ?> hover:border-slate-300 cursor-pointer text-center block transition has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50">
                                <input type="radio" name="durasi" value="6 Bulan" data-multiplier="6" <?= ($form_durasi === '6 Bulan') ? 'checked' : '' ?> class="durasi-radio sr-only peer" onchange="updateHargaSewa()">
                                <span class="font-extrabold <?= ($form_durasi === '6 Bulan') ? 'text-blue-900' : 'text-slate-800' ?> peer-checked:text-blue-900 block text-xs judul-durasi">6 Bulan</span>
                                <span class="text-[10px] text-slate-400 peer-checked:text-slate-600 font-semibold">6× Bulan</span>
                            </label>

                            <label class="durasi-label p-3 rounded-2xl border-2 <?= ($form_durasi === 'Tahunan') ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200 bg-white' ?> hover:border-slate-300 cursor-pointer text-center block transition has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50">
                                <input type="radio" name="durasi" value="Tahunan" data-multiplier="12" <?= ($form_durasi === 'Tahunan') ? 'checked' : '' ?> class="durasi-radio sr-only peer" onchange="updateHargaSewa()">
                                <span class="font-extrabold <?= ($form_durasi === 'Tahunan') ? 'text-blue-900' : 'text-slate-800' ?> peer-checked:text-blue-900 block text-xs judul-durasi">Tahunan</span>
                                <span class="text-[10px] text-slate-400 peer-checked:text-slate-600 font-semibold">12× Bulan</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- IDENTITAS PENYEWA (NIK UNIK + WHATSAPP) -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                        <h3 class="text-sm font-extrabold text-slate-900">Identitas Calon Penyewa</h3>
                        <span class="bg-blue-50 text-blue-700 text-[10px] font-bold px-2.5 py-0.5 rounded-full">1 NIK / Akun = Maks. 1 Kos Aktif</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-700">Nama Lengkap Sesuai KTP *</label>
                            <input type="text" name="nama_lengkap" value="<?= htmlspecialchars($form_nama) ?>" placeholder="Contoh: Gede Wikananda Baruna" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-bold text-slate-800">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-700">NIK (16 Digit KTP) *</label>
                            <input type="text" name="nik" value="<?= htmlspecialchars($form_nik) ?>" maxlength="16" pattern="[0-9]{16}" oninput="this.value=this.value.replace(/[^0-9]/g,'')" placeholder="Contoh: 5103051211050001" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-mono font-bold text-slate-800">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-700">Nomor WhatsApp *</label>
                            <input type="text" name="no_hp" value="<?= htmlspecialchars($form_nohp) ?>" placeholder="Contoh: 081xxxxxxx" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-bold text-slate-800">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-700">Pekerjaan *</label>
                            <input type="text" name="pekerjaan" value="<?= htmlspecialchars($form_pekerjaan) ?>" placeholder="Contoh: Mahasiswa / Karyawan Swasta" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-bold text-slate-800">
                        </div>
                        <div class="space-y-1.5 sm:col-span-2">
                            <label class="font-extrabold text-slate-700">Email *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($form_email) ?>" placeholder="Contoh: user@gmail.com" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-bold text-slate-800">
                        </div>
                    </div>
                </div>
            </div>

            <!-- KANAN: RINGKASAN BIAYA -->
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 sticky top-20 text-xs">
                    <h3 class="text-sm font-extrabold text-slate-900 border-b border-slate-100 pb-3">Ringkasan Biaya Awal</h3>

                    <div class="space-y-2 text-slate-600">
                        <div class="flex justify-between">
                            <span id="label-durasi-teks">Sewa (<?= htmlspecialchars($form_durasi) ?>)</span>
                            <span class="font-bold text-slate-900" id="display-harga-sewa">Rp <?= number_format($harga_bln * $multiplier_awal, 0, ',', '.') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Deposit Jaminan (Refundable)</span>
                            <span class="font-bold text-slate-900">Rp 500.000</span>
                        </div>
                        <div class="pt-3 border-t border-slate-200 flex justify-between items-baseline">
                            <span class="font-extrabold text-slate-900 text-sm">Total Pembayaran</span>
                            <span class="text-lg font-extrabold text-blue-600" id="display-total-bayar">Rp <?= number_format($total_harga_awal, 0, ',', '.') ?></span>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold py-3.5 rounded-xl transition shadow-md flex items-center justify-center gap-2">
                        <span id="btn-text-bayar">Lanjut ke Pembayaran (Rp <?= number_format($total_harga_awal, 0, ',', '.') ?>)</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
            </div>

        </form>
        <?php endif; ?>

    </main>

    <script>
        function gantiFotoUtama(src, btnEl) {
            const utama = document.getElementById('foto-utama-sewa');
            if (utama) {
                utama.src = src;
            }
            document.querySelectorAll('.thumb-foto').forEach(btn => {
                btn.classList.remove('border-blue-600', 'ring-1', 'ring-blue-600');
                btn.classList.add('border-slate-200', 'opacity-75');
            });
            if (btnEl) {
                btnEl.classList.remove('border-slate-200', 'opacity-75');
                btnEl.classList.add('border-blue-600', 'ring-1', 'ring-blue-600');
            }
        }

        const hargaBulanan = <?= $harga_bln ?>;
        const deposit = 500000;

        function formatRupiah(angka) {
            return 'Rp ' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        function updateHargaSewa() {
            const selectedRadio = document.querySelector('.durasi-radio:checked');
            if (!selectedRadio) return;

            const multiplier = parseInt(selectedRadio.getAttribute('data-multiplier'));
            const durasiNama = selectedRadio.value;
            const totalSewa = hargaBulanan * multiplier;
            const totalBayar = totalSewa + deposit;

            document.querySelectorAll('.durasi-label').forEach(label => {
                label.classList.remove('border-blue-600', 'bg-blue-50/50');
                label.classList.add('border-slate-200', 'bg-white');
                const judul = label.querySelector('.judul-durasi');
                judul.classList.remove('text-blue-900');
                judul.classList.add('text-slate-800');
            });

            const activeLabel = selectedRadio.closest('.durasi-label');
            activeLabel.classList.remove('border-slate-200', 'bg-white');
            activeLabel.classList.add('border-blue-600', 'bg-blue-50/50');
            const activeJudul = activeLabel.querySelector('.judul-durasi');
            activeJudul.classList.remove('text-slate-800');
            activeJudul.classList.add('text-blue-900');

            document.getElementById('label-durasi-teks').innerText = `Sewa (${durasiNama})`;
            document.getElementById('display-harga-sewa').innerText = formatRupiah(totalSewa);
            document.getElementById('display-total-bayar').innerText = formatRupiah(totalBayar);
            document.getElementById('btn-text-bayar').innerText = `Lanjut ke Pembayaran (${formatRupiah(totalBayar)})`;
        }
    </script>
</body>
</html>