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

$id_mitra = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

// Ambil data Mitra
$q_mitra = @mysqli_query($conn, "SELECT * FROM mitra WHERE id_mitra = $id_mitra");
$data_mitra = $q_mitra ? mysqli_fetch_assoc($q_mitra) : null;
$nama_mitra = $data_mitra['nama_mitra'] ?? $data_mitra['nama'] ?? $_SESSION['nama'] ?? 'Mitra KosLink';

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

$cols_prop      = getTableColumns($conn, 'properti_kos');
$pk_prop        = in_array('id_properti', $cols_prop, true) ? 'id_properti' : 'id_kos';
$col_nama_kos   = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : 'nama_properti';
$col_mitra_prop = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : (in_array('id_user', $cols_prop, true) ? 'id_user' : 'id_pemilik');

// Ambil daftar properti milik Mitra
$q_properti_list = @mysqli_query($conn, "SELECT * FROM properti_kos WHERE `$col_mitra_prop` = $id_mitra ORDER BY `$pk_prop` ASC");
$daftar_properti = [];
if ($q_properti_list) {
    while ($p = mysqli_fetch_assoc($q_properti_list)) {
        $daftar_properti[] = $p;
    }
}

$selected_properti_id = isset($_REQUEST['properti']) ? (int)$_REQUEST['properti'] : ($daftar_properti[0][$pk_prop] ?? 0);
$properti_aktif = null;
foreach ($daftar_properti as $p) {
    if ((int)$p[$pk_prop] === $selected_properti_id) {
        $properti_aktif = $p;
        break;
    }
}
if (!$properti_aktif && count($daftar_properti) > 0) {
    $properti_aktif = $daftar_properti[0];
    $selected_properti_id = (int)$properti_aktif[$pk_prop];
}

// Ambil daftar kamar milik properti aktif & hitung total sisa unit kosong
$daftar_kamar     = [];
$kamar_ids_prop   = [];
$sisa_unit_kosong = 0;
$cols_kamar       = getTableColumns($conn, 'kamar');
if (count($cols_kamar) > 0 && $selected_properti_id > 0) {
    $fk_kamar     = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : 'id_kos';
    $pk_kamar     = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : 'id';
    $col_no_kamar = in_array('nomor_kamar', $cols_kamar, true) ? 'nomor_kamar' : (in_array('nama_kamar', $cols_kamar, true) ? 'nama_kamar' : 'tipe_kamar');
    $col_sisa_k   = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : (in_array('jumlah_unit', $cols_kamar, true) ? 'jumlah_unit' : '');

    $q_km = @mysqli_query($conn, "SELECT * FROM kamar WHERE `$fk_kamar` = $selected_properti_id ORDER BY `$pk_kamar` ASC");
    if ($q_km) {
        while ($km = mysqli_fetch_assoc($q_km)) {
            $kid = (int)$km[$pk_kamar];
            $kamar_ids_prop[] = $kid;
            if ($col_sisa_k !== '') {
                $sisa_unit_kosong += max(0, (int)($km[$col_sisa_k] ?? 0));
            }
            $daftar_kamar[] = [
                'id'    => $kid,
                'label' => $km[$col_no_kamar] ?? ('Kamar #' . $kid)
            ];
        }
    }
}

$notif_sukses = '';
$notif_error  = '';

// PROSES AKSI POST (Daftarkan Penyewa Manual, Tandai Lunas, Akhiri Kontrak)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi'])) {
    $aksi = $_POST['aksi'];

    if ($aksi === 'tambah_penyewa') {
        $nama_penyewa  = mysqli_real_escape_string($conn, trim($_POST['nama_penyewa'] ?? ''));
        $no_hp         = mysqli_real_escape_string($conn, trim($_POST['no_hp'] ?? ''));
        $no_kamar      = mysqli_real_escape_string($conn, trim($_POST['no_kamar'] ?? ''));
        $pekerjaan     = mysqli_real_escape_string($conn, trim($_POST['pekerjaan'] ?? 'Mahasiswa / Karyawan'));
        $skema_kontrak = mysqli_real_escape_string($conn, trim($_POST['skema_kontrak'] ?? 'Bulanan'));
        $tanggal_masuk = mysqli_real_escape_string($conn, $_POST['tanggal_masuk'] ?? date('Y-m-d'));
        $jatuh_tempo   = mysqli_real_escape_string($conn, $_POST['jatuh_tempo'] ?? date('Y-m-d', strtotime('+1 month')));
        $nominal_sewa  = (int)preg_replace('/[^0-9]/', '', $_POST['nominal_sewa'] ?? '0');
        $status_bayar  = mysqli_real_escape_string($conn, trim($_POST['status_bayar'] ?? 'Lunas'));

        if ($nama_penyewa !== '' && $no_kamar !== '') {
            // Cek aturan 1 penyewa maksimal 1 kos aktif (berdasarkan No. HP)
            $hp_digits = preg_replace('/[^0-9]/', '', $_POST['no_hp'] ?? '');
            $sudah_aktif_lain = false;
            if ($hp_digits !== '') {
                $hp_esc = mysqli_real_escape_string($conn, $hp_digits);
                $q_dup = @mysqli_query(
                    $conn,
                    "SELECT id_penyewa, no_kamar FROM penyewa 
                     WHERE REPLACE(REPLACE(REPLACE(no_hp, '-', ''), ' ', ''), '+', '') = '$hp_esc' 
                       AND LOWER(TRIM(COALESCE(status_hunian, ''))) = 'aktif' 
                     LIMIT 1"
                );
                if ($q_dup && mysqli_num_rows($q_dup) > 0) {
                    $sudah_aktif_lain = true;
                }
            }

            if ($sudah_aktif_lain) {
                $notif_error = "Pendaftaran ditolak: Nomor HP/WhatsApp tersebut sudah terdaftar pada hunian kos aktif lain (maksimal 1 kos per penyewa).";
            } else {
                $id_kamar_match = 0;
                foreach ($daftar_kamar as $dk) {
                    if (strcasecmp(trim($dk['label']), trim($_POST['no_kamar'])) === 0) {
                        $id_kamar_match = (int)$dk['id'];
                        break;
                    }
                }

                $sql_ins = "INSERT INTO penyewa (id_properti, id_kamar, no_kamar, nama_penyewa, no_hp, pekerjaan, skema_kontrak, tanggal_masuk, jatuh_tempo, nominal_sewa, status_bayar, status_escrow, status_hunian)
                            VALUES ($selected_properti_id, $id_kamar_match, '$no_kamar', '$nama_penyewa', '$no_hp', '$pekerjaan', '$skema_kontrak', '$tanggal_masuk', '$jatuh_tempo', $nominal_sewa, '$status_bayar', 'Manual Mitra', 'Aktif')";
                if (@mysqli_query($conn, $sql_ins)) {
                    $id_new_p = mysqli_insert_id($conn);
                    if ($id_kamar_match > 0) {
                        $col_sisa_upd = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : 'jumlah_unit';
                        @mysqli_query($conn, "UPDATE kamar SET `$col_sisa_upd` = GREATEST(0, `$col_sisa_upd` - 1) WHERE id_kamar = $id_kamar_match");
                        @mysqli_query($conn, "INSERT IGNORE INTO stok_kamar_log (id_penyewa, id_kamar) VALUES ($id_new_p, $id_kamar_match)");
                        $sisa_unit_kosong = max(0, $sisa_unit_kosong - 1);
                    }
                    $notif_sukses = "Penyewa $nama_penyewa ($no_kamar) berhasil didaftarkan.";
                } else {
                    $notif_error = "Gagal mendaftarkan penyewa: " . mysqli_error($conn);
                }
            }
        } else {
            $notif_error = "Nama penyewa dan nomor kamar wajib diisi.";
        }
        // Di dalam blok if ($aksi === 'tambah_penyewa') pada mitra/penyewa.php:
        $nik_raw   = preg_replace('/[^0-9]/', '', $_POST['nik'] ?? '');
        $hp_digits = preg_replace('/[^0-9]/', '', $_POST['no_hp'] ?? '');

        $cond_dup = [];
        if ($nik_raw !== '') {
            $nik_esc = mysqli_real_escape_string($conn, $nik_raw);
            $cond_dup[] = "nik = '$nik_esc'";
        }
        if ($hp_digits !== '') {
            $hp_esc = mysqli_real_escape_string($conn, $hp_digits);
            $cond_dup[] = "REPLACE(REPLACE(REPLACE(no_hp, '-', ''), ' ', ''), '+', '') = '$hp_esc'";
        }

        if (!empty($cond_dup)) {
            $where_dup = '(' . implode(' OR ', $cond_dup) . ')';
            $q_dup = @mysqli_query(
                $conn,
                "SELECT id_penyewa, no_kamar FROM penyewa 
                WHERE $where_dup 
                AND LOWER(TRIM(COALESCE(status_hunian, ''))) = 'aktif' 
                LIMIT 1"
            );
            if ($q_dup && mysqli_num_rows($q_dup) > 0) {
                $notif_error = "Pendaftaran ditolak: NIK atau Nomor HP tersebut sudah terdaftar aktif pada hunian kos lain (maksimal 1 kos per penyewa).";
            }
        }
    }

    if ($aksi === 'tandai_lunas') {
        $id_penyewa = (int)($_POST['id_penyewa'] ?? 0);
        if ($id_penyewa > 0) {
            @mysqli_query($conn, "UPDATE penyewa SET status_bayar = 'Lunas', status_hunian = 'Aktif', jatuh_tempo = DATE_ADD(jatuh_tempo, INTERVAL 1 MONTH) WHERE id_penyewa = $id_penyewa");
            $notif_sukses = "Status tagihan penyewa diperbarui menjadi Lunas.";
        }
    }

    if ($aksi === 'hapus_penyewa') {
        $id_penyewa = (int)($_POST['id_penyewa'] ?? 0);
        if ($id_penyewa > 0) {
            // Kembalikan stok kamar +1 jika penyewa sebelumnya mengurangi stok
            $q_p_del = @mysqli_query($conn, "SELECT id_kamar FROM penyewa WHERE id_penyewa = $id_penyewa LIMIT 1");
            if ($q_p_del && ($r_del = mysqli_fetch_assoc($q_p_del))) {
                $kid_del = (int)($r_del['id_kamar'] ?? 0);
                if ($kid_del > 0) {
                    $col_sisa_upd = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : 'jumlah_unit';
                    @mysqli_query($conn, "UPDATE kamar SET `$col_sisa_upd` = `$col_sisa_upd` + 1 WHERE id_kamar = $kid_del");
                    @mysqli_query($conn, "DELETE FROM stok_kamar_log WHERE id_penyewa = $id_penyewa AND id_kamar = $kid_del");
                    $sisa_unit_kosong++;
                }
            }
            @mysqli_query($conn, "DELETE FROM penyewa WHERE id_penyewa = $id_penyewa");
            $notif_sukses = "Data kontrak penyewa telah dihapus dan stok kamar dikembalikan.";
        }
    }
}

// Ambil seluruh data penyewa untuk properti aktif (sinkron dengan dashboard.php)
$daftar_penyewa  = [];
$jml_aktif       = 0;
$jml_lunas       = 0;
$jml_jatuh_tempo = 0;
$jml_menunggak   = 0;

if ($selected_properti_id > 0) {
    $conds = ["id_properti = $selected_properti_id"];
    if (!empty($kamar_ids_prop)) {
        $in_k = implode(',', array_map('intval', $kamar_ids_prop));
        $conds[] = "id_kamar IN ($in_k)";
    }
    $where_pny = '(' . implode(' OR ', $conds) . ')';

    // Sinkronkan status_hunian = 'Aktif' untuk penyewa yang status_bayar-nya sudah 'Lunas'
    @mysqli_query(
        $conn,
        "UPDATE penyewa 
         SET status_hunian = 'Aktif' 
         WHERE $where_pny 
           AND LOWER(TRIM(COALESCE(status_bayar, ''))) IN ('lunas', 'paid', 'terbayar')
           AND LOWER(TRIM(COALESCE(status_hunian, ''))) IN ('', 'pending', 'menunggu')"
    );

    $q_pny = @mysqli_query($conn, "SELECT * FROM penyewa WHERE $where_pny ORDER BY id_penyewa DESC");
    if ($q_pny) {
        while ($row = mysqli_fetch_assoc($q_pny)) {
            $st_h = strtolower(trim($row['status_hunian'] ?? 'aktif'));
            $sb   = strtolower(trim($row['status_bayar'] ?? ''));

            $is_keluar = in_array($st_h, ['selesai', 'keluar', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif'], true);
            $is_aktif  = !$is_keluar && (
                in_array($st_h, ['aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true) ||
                in_array($sb, ['lunas', 'paid', 'terbayar', 'berhasil'], true)
            );

            if (!$is_aktif) {
                continue;
            }

            $daftar_penyewa[] = $row;
            $jml_aktif++;
            if ($sb === 'lunas' || $sb === 'paid' || $sb === 'terbayar') {
                $jml_lunas++;
            } elseif ($sb === 'menunggak' || $sb === 'terlambat') {
                $jml_menunggak++;
            } else {
                $jml_jatuh_tempo++;
            }
        }
    }
}

// Total unit kamar keseluruhan = Sisa unit kosong + Penghuni aktif saat ini
$total_kamar_prop = $sisa_unit_kosong + $jml_aktif;
$persen_okupansi  = $total_kamar_prop > 0 ? round(($jml_aktif / $total_kamar_prop) * 100) : 0;
$persen_tertib    = $jml_aktif > 0 ? round(($jml_lunas / $jml_aktif) * 100) : 100;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar &amp; Pengawasan Penyewa Kos - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media print {
            aside, header, .no-print { display: none !important; }
            .print-full { padding-left: 0 !important; }
        }
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
                    
                    <a href="penyewa.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <i class="fa-solid fa-users w-4 text-center"></i>
                        <span>Daftar &amp; Awasi Penyewa</span>
                    </a>
                    <a href="keluhan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-headset w-4 text-center text-slate-400"></i>
                        <span>Kelola Keluhan &amp; Komplain</span>
                    </a>
                    <a href="laporan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-chart-line w-4 text-center text-slate-400"></i>
                        <span>Laporan Penghasilan &amp; Rekber</span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="p-4 space-y-3">
            <div class="bg-slate-50 border border-slate-200/70 rounded-2xl p-4">
                <div class="flex items-center gap-2 text-blue-600 font-bold text-xs mb-1.5">
                    <i class="fa-solid fa-circle-question"></i>
                    <span>Bantuan Mitra</span>
                </div>
                <p class="text-[11px] text-slate-500 leading-relaxed mb-3">Pusat resolusi kendala operasional &amp; klaim garansi kos.</p>
                <a href="https://wa.me/6281234567890" target="_blank" class="text-xs font-bold text-blue-600 hover:text-blue-700 inline-flex items-center gap-1">
                    Hubungi CS KosLink <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>
            <a href="../auth/logout.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 text-xs font-bold transition">
                <i class="fa-solid fa-right-from-bracket"></i> Keluar Akun
            </a>
        </div>
    </aside>

    <!-- KONTEN UTAMA -->
    <div class="flex-1 lg:pl-64 flex flex-col min-w-0 print-full">

        <!-- TOPBAR -->
        <header class="bg-white border-b border-slate-200/80 px-6 lg:px-8 py-3.5 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-30">
            <div class="flex items-center gap-3 flex-wrap">
                <form method="GET" action="" class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5">
                    <i class="fa-solid fa-house-chimney text-blue-600 text-xs mr-2.5"></i>
                    <div class="mr-2">
                        <p class="text-[9px] font-bold text-slate-400 uppercase leading-none">Properti Aktif</p>
                        <select name="properti" onchange="this.form.submit()" class="bg-transparent text-xs font-bold text-slate-800 focus:outline-none cursor-pointer pr-2">
                            <?php if (count($daftar_properti) > 0): ?>
                                <?php foreach ($daftar_properti as $prop): ?>
                                    <option value="<?= (int)$prop[$pk_prop] ?>" <?= (int)$prop[$pk_prop] === $selected_properti_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prop[$col_nama_kos]) ?>
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

            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_mitra) ?></p>
                    <p class="text-[11px] text-slate-400 font-medium">Mitra Terdaftar</p>
                </div>
                <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 border border-blue-200 flex items-center justify-center font-bold text-sm">
                    <i class="fa-regular fa-user"></i>
                </div>
            </div>
        </header>

        <!-- BODY HALAMAN -->
        <main class="p-6 lg:p-8 max-w-[1400px] w-full mx-auto space-y-6">

            <?php if ($notif_sukses): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3 text-xs font-bold">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
                    <span><?= htmlspecialchars($notif_sukses) ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <?php endif; ?>

            <?php if ($notif_error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3 text-xs font-bold">
                    <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
                    <span><?= htmlspecialchars($notif_error) ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <?php endif; ?>

            <!-- HEADER JUDUL & TOMBOL AKSI -->
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Daftar &amp; Pengawasan Penyewa Kos</h2>
                    <p class="text-xs text-slate-500 mt-1 max-w-2xl leading-relaxed">
                        Pantau kontrak sewa aktif, identitas penghuni, riwayat check-in, dan pengawasan status pelunasan sewa bulanan dengan sistem rekber KosLink Escrow.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2.5 no-print">
                    <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-3.5 py-2.5 rounded-xl text-xs font-bold transition shadow-sm">
                        <i class="fa-solid fa-download text-slate-500"></i> Download Laporan (PDF/Excel)
                    </button>
                    <button type="button" onclick="bukaModal('modal-tambah-penyewa')" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition shadow-sm shadow-blue-200">
                        <i class="fa-solid fa-user-plus"></i> + Daftarkan Penyewa Manual
                    </button>
                </div>
            </div>

            <!-- 4 KARTU KPI PENGAWASAN PENYEWA -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-white border border-slate-200/80 rounded-2xl p-5 flex flex-col justify-between shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">TOTAL PENGHUNI AKTIF</span>
                        <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-extrabold text-slate-900"><?= $jml_aktif ?></span>
                        <span class="text-xs text-slate-400 font-semibold">/ <?= $total_kamar_prop ?> Kamar Terisi (<?= $persen_okupansi ?>%)</span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-100 rounded-full mt-4 overflow-hidden">
                        <div class="h-full bg-blue-600 rounded-full" style="width: <?= min(100, $persen_okupansi) ?>%"></div>
                    </div>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-2xl p-5 flex flex-col justify-between shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">LUNAS TEPAT WAKTU</span>
                            <div class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">
                                <i class="fa-regular fa-circle-check"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-extrabold text-slate-900"><?= $jml_lunas ?></span>
                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold px-2 py-0.5 rounded-full"><?= $persen_tertib ?>% Tertib</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-4">Dana escrow siap ditarik mitra</p>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-2xl p-5 flex flex-col justify-between shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">JATUH TEMPO &lt; 7 HARI</span>
                            <div class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs">
                                <i class="fa-regular fa-clock"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-extrabold text-slate-900"><?= $jml_jatuh_tempo ?></span>
                            <span class="bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-extrabold px-2 py-0.5 rounded-full">Reminder Aktif</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-4">Pengingat WhatsApp H-3 dikirim</p>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-2xl p-5 flex flex-col justify-between shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">MENUNGGAK / TERLAMBAT</span>
                            <div class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-circle-exclamation"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-extrabold text-slate-900"><?= $jml_menunggak ?></span>
                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold px-2 py-0.5 rounded-full"><?= $jml_menunggak ?> Tunggakan</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-4">Proses notifikasi lanjutan KosLink</p>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm no-print">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
                    <div class="md:col-span-5 relative">
                        <i class="fa-solid fa-magnifying-glass text-slate-400 text-xs absolute left-3.5 top-3"></i>
                        <input type="text" id="search-penyewa" oninput="filterPenyewa()" placeholder="Cari nama penyewa atau nomor kamar..." class="w-full pl-9 pr-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="md:col-span-3">
                        <select id="filter-status-tagihan" onchange="filterPenyewa()" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="semua">Semua Status Tagihan</option>
                            <option value="lunas">Lunas</option>
                            <option value="jatuh tempo">Jatuh Tempo</option>
                            <option value="menunggak">Menunggak / Terlambat</option>
                        </select>
                    </div>

                    <div class="md:col-span-3">
                        <select id="filter-skema" onchange="filterPenyewa()" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="semua">Tipe Kontrak Sewa</option>
                            <option value="bulanan">Bulanan</option>
                            <option value="3 bulan">Per 3 Bulan</option>
                            <option value="tahunan">Tahunan</option>
                        </select>
                    </div>

                    <div class="md:col-span-1 flex justify-end">
                        <button type="button" onclick="resetFilterPenyewa()" title="Reset Filter" class="w-9 h-9 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-500 flex items-center justify-center transition">
                            <i class="fa-solid fa-filter text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- TABEL PENGAWASAN PENYEWA -->
            <div class="bg-white border border-slate-200/80 rounded-3xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200/80 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">
                                <th class="py-4 px-6">KAMAR &amp; IDENTITAS PENGHUNI</th>
                                <th class="py-4 px-4">SKEMA KONTRAK</th>
                                <th class="py-4 px-4">SIKLUS TAGIHAN &amp; JATUH TEMPO</th>
                                <th class="py-4 px-4">STATUS PEMBAYARAN BULAN INI</th>
                                <th class="py-4 px-4">ESCROW REKBER KOSLINK</th>
                                <th class="py-4 px-6 text-right">AKSI MANAJEMEN</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs">
                            <?php if (count($daftar_penyewa) === 0): ?>
                            <tr>
                                <td colspan="6" class="py-16 px-6 text-center">
                                    <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-xl mx-auto mb-3">
                                        <i class="fa-solid fa-user-group"></i>
                                    </div>
                                    <h3 class="text-sm font-extrabold text-slate-900">Belum Ada Penyewa Terdaftar</h3>
                                    <p class="text-xs text-slate-400 mt-1 mb-5">Penyewa yang melakukan booking atau didaftarkan manual akan muncul di tabel ini.</p>
                                    <button type="button" onclick="bukaModal('modal-tambah-penyewa')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition shadow-sm no-print">
                                        + Daftarkan Penyewa Manual
                                    </button>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($daftar_penyewa as $pny):
                                    $sb = strtolower(trim($pny['status_bayar'] ?? ''));
                                    $hp_clean = preg_replace('/[^0-9]/', '', $pny['no_hp'] ?? '');
                                    if (substr($hp_clean, 0, 1) === '0') $hp_clean = '62' . substr($hp_clean, 1);
                                ?>
                                <tr class="baris-penyewa hover:bg-slate-50/70 transition"
                                    data-search="<?= htmlspecialchars(strtolower(($pny['nama_penyewa'] ?? '') . ' ' . ($pny['no_kamar'] ?? ''))) ?>"
                                    data-status="<?= htmlspecialchars($sb) ?>"
                                    data-skema="<?= htmlspecialchars(strtolower($pny['skema_kontrak'] ?? '')) ?>">
                                    <td class="py-4 px-6">
                                        <span class="inline-block bg-blue-50 text-blue-700 font-extrabold text-[10px] px-2 py-0.5 rounded-md mb-1">
                                            <?= htmlspecialchars($pny['no_kamar'] ?? '-') ?>
                                        </span>
                                        <p class="font-extrabold text-slate-900"><?= htmlspecialchars($pny['nama_penyewa'] ?? '-') ?></p>
                                        <p class="text-[11px] text-slate-400"><?= htmlspecialchars($pny['pekerjaan'] ?? 'Penghuni') ?></p>
                                    </td>
                                    <td class="py-4 px-4">
                                        <p class="font-bold text-slate-800"><?= htmlspecialchars($pny['skema_kontrak'] ?? '-') ?></p>
                                        <p class="text-[11px] text-slate-400">Masuk: <?= !empty($pny['tanggal_masuk']) ? date('d M Y', strtotime($pny['tanggal_masuk'])) : '-' ?></p>
                                    </td>
                                    <td class="py-4 px-4">
                                        <p class="font-extrabold text-slate-900">Rp <?= number_format((float)($pny['nominal_sewa'] ?? 0), 0, ',', '.') ?></p>
                                        <p class="text-[11px] text-slate-500">Jatuh Tempo: <?= !empty($pny['jatuh_tempo']) ? date('d M Y', strtotime($pny['jatuh_tempo'])) : '-' ?></p>
                                    </td>
                                    <td class="py-4 px-4">
                                        <?php if ($sb === 'lunas' || $sb === 'paid' || $sb === 'terbayar'): ?>
                                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                                <i class="fa-solid fa-check text-[9px]"></i> Lunas
                                            </span>
                                        <?php elseif ($sb === 'menunggak' || $sb === 'terlambat'): ?>
                                            <span class="inline-flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                                <i class="fa-solid fa-triangle-exclamation text-[9px]"></i> Menunggak
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                                <i class="fa-regular fa-clock text-[9px]"></i> <?= htmlspecialchars($pny['status_bayar'] ?: 'Menunggu') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold px-2.5 py-1 rounded-lg">
                                            <i class="fa-solid fa-shield-halved text-[9px]"></i> <?= htmlspecialchars($pny['status_escrow'] ?? 'Terverifikasi') ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-right space-x-1.5 no-print whitespace-nowrap">
                                        <?php if ($hp_clean): ?>
                                        <a href="https://wa.me/<?= $hp_clean ?>" target="_blank" title="Chat WhatsApp" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition">
                                            <i class="fa-brands fa-whatsapp text-sm"></i>
                                        </a>
                                        <?php endif; ?>

                                        <?php if ($sb !== 'lunas'): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="aksi" value="tandai_lunas">
                                            <input type="hidden" name="id_penyewa" value="<?= (int)$pny['id_penyewa'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 text-[11px] font-bold transition">
                                                Tandai Lunas
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <form method="POST" class="inline" onsubmit="return confirm('Hapus data penyewa ini?');">
                                            <input type="hidden" name="aksi" value="hapus_penyewa">
                                            <input type="hidden" name="id_penyewa" value="<?= (int)$pny['id_penyewa'] ?>">
                                            <button type="submit" title="Hapus" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 transition">
                                                <i class="fa-regular fa-trash-can text-xs"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- MODAL DAFTARKAN PENYEWA MANUAL -->
    <div id="modal-tambah-penyewa" class="fixed inset-0 z-50 bg-slate-900/50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-extrabold text-slate-900">Daftarkan Penyewa Manual</h3>
                <button type="button" onclick="tutupModal('modal-tambah-penyewa')" class="text-slate-400 hover:text-slate-700">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="aksi" value="tambah_penyewa">
                <input type="hidden" name="properti" value="<?= $selected_properti_id ?>">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nama Lengkap Penyewa</label>
                    <input type="text" name="nama_penyewa" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-medium">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">No. HP / WhatsApp</label>
                        <input type="text" name="no_hp" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-medium">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Nomor / Nama Kamar</label>
                        <input type="text" name="no_kamar" list="list-kamar-mitra" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-medium">
                        <datalist id="list-kamar-mitra">
                            <?php foreach ($daftar_kamar as $dk): ?>
                                <option value="<?= htmlspecialchars($dk['label']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Skema Kontrak</label>
                        <select name="skema_kontrak" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold">
                            <option value="Bulanan">Bulanan</option>
                            <option value="Per 3 Bulan">Per 3 Bulan</option>
                            <option value="Tahunan">Tahunan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Status Pembayaran</label>
                        <select name="status_bayar" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold">
                            <option value="Lunas">Lunas</option>
                            <option value="Menunggu Pembayaran">Menunggu Pembayaran</option>
                            <option value="Menunggak">Menunggak</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Tanggal Masuk</label>
                        <input type="date" name="tanggal_masuk" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-medium">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Jatuh Tempo</label>
                        <input type="date" name="jatuh_tempo" value="<?= date('Y-m-d', strtotime('+1 month')) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-medium">
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nominal Sewa (Rp)</label>
                    <input type="number" name="nominal_sewa" value="1500000" min="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="tutupModal('modal-tambah-penyewa')" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">Batal</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-extrabold">Simpan Penyewa</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function bukaModal(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('hidden');
                el.classList.add('flex');
            }
        }
        function tutupModal(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('hidden');
                el.classList.remove('flex');
            }
        }
        function filterPenyewa() {
            const q = (document.getElementById('search-penyewa')?.value || '').toLowerCase().trim();
            const st = (document.getElementById('filter-status-tagihan')?.value || 'semua').toLowerCase();
            const sk = (document.getElementById('filter-skema')?.value || 'semua').toLowerCase();
            const rows = document.querySelectorAll('.baris-penyewa');

            rows.forEach((tr) => {
                const text = tr.getAttribute('data-search') || '';
                const status = tr.getAttribute('data-status') || '';
                const skema = tr.getAttribute('data-skema') || '';

                const matchQ = !q || text.includes(q);
                const matchSt = st === 'semua' || status.includes(st);
                const matchSk = sk === 'semua' || skema.includes(sk);

                tr.style.display = (matchQ && matchSt && matchSk) ? '' : 'none';
            });
        }
        function resetFilterPenyewa() {
            const s = document.getElementById('search-penyewa');
            const st = document.getElementById('filter-status-tagihan');
            const sk = document.getElementById('filter-skema');
            if (s) s.value = '';
            if (st) st.value = 'semua';
            if (sk) sk.value = 'semua';
            filterPenyewa();
        }
    </script>
</body>
</html>