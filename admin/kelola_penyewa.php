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

$id_mitra = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

// Ambil data Mitra
$q_mitra = mysqli_query($conn, "SELECT * FROM mitra WHERE id_mitra = $id_mitra");
$data_mitra = $q_mitra ? mysqli_fetch_assoc($q_mitra) : null;
$nama_mitra = $data_mitra['nama_mitra'] ?? $data_mitra['nama'] ?? $_SESSION['nama'] ?? 'Mitra KosLink';

// Helper deteksi kolom tabel otomatis
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
$pk_prop        = in_array('id_properti', $cols_prop) ? 'id_properti' : 'id_kos';
$col_nama_kos   = in_array('nama_kos', $cols_prop) ? 'nama_kos' : 'nama_properti';
$col_mitra_prop = in_array('id_mitra', $cols_prop) ? 'id_mitra' : (in_array('id_user', $cols_prop) ? 'id_user' : 'id_pemilik');

// Ambil daftar properti milik Mitra
$q_properti_list = mysqli_query($conn, "SELECT * FROM properti_kos WHERE `$col_mitra_prop` = $id_mitra ORDER BY `$pk_prop` ASC");
$daftar_properti = [];
if ($q_properti_list) {
    while ($p = mysqli_fetch_assoc($q_properti_list)) {
        $daftar_properti[] = $p;
    }
}

$selected_properti_id = isset($_REQUEST['properti']) ? (int)$_REQUEST['properti'] : ($daftar_properti[0][$pk_prop] ?? 0);
$properti_aktif = null;
foreach ($daftar_properti as $p) {
    if ($p[$pk_prop] == $selected_properti_id) {
        $properti_aktif = $p;
        break;
    }
}
if (!$properti_aktif && count($daftar_properti) > 0) {
    $properti_aktif = $daftar_properti[0];
    $selected_properti_id = $properti_aktif[$pk_prop];
}

// Ambil daftar kamar milik properti aktif untuk dropdown pendaftaran penyewa & hitung total kamar
$daftar_kamar = [];
$total_kamar_prop = 0;
$cols_kamar = getTableColumns($conn, 'kamar');
if (count($cols_kamar) > 0 && $selected_properti_id > 0) {
    $fk_kamar = in_array('id_properti', $cols_kamar) ? 'id_properti' : 'id_kos';
    $pk_kamar = in_array('id_kamar', $cols_kamar) ? 'id_kamar' : 'id';
    $col_no_kamar = in_array('nomor_kamar', $cols_kamar) ? 'nomor_kamar' : (in_array('nama_kamar', $cols_kamar) ? 'nama_kamar' : 'tipe_kamar');
    $q_km = @mysqli_query($conn, "SELECT * FROM kamar WHERE `$fk_kamar` = $selected_properti_id ORDER BY `$pk_kamar` ASC");
    if ($q_km) {
        while ($km = mysqli_fetch_assoc($q_km)) {
            $total_kamar_prop++;
            $daftar_kamar[] = [
                'id'    => $km[$pk_kamar],
                'label' => $km[$col_no_kamar] ?? ('Kamar #' . $km[$pk_kamar])
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
            $sql_ins = "INSERT INTO Penyewa (id_properti, no_kamar, nama_penyewa, no_hp, pekerjaan, skema_kontrak, tanggal_masuk, jatuh_tempo, nominal_sewa, status_bayar, status_escrow, status_hunian)
                        VALUES ($selected_properti_id, '$no_kamar', '$nama_penyewa', '$no_hp', '$pekerjaan', '$skema_kontrak', '$tanggal_masuk', '$jatuh_tempo', $nominal_sewa, '$status_bayar', 'Manual Mitra', 'Aktif')";
            if (mysqli_query($conn, $sql_ins)) {
                $notif_sukses = "Penyewa $nama_penyewa ($no_kamar) berhasil didaftarkan.";
            } else {
                $notif_error = "Gagal mendaftarkan penyewa: " . mysqli_error($conn);
            }
        } else {
            $notif_error = "Nama penyewa dan nomor kamar wajib diisi.";
        }
    }

    if ($aksi === 'tandai_lunas') {
        $id_penyewa = (int)($_POST['id_penyewa'] ?? 0);
        if ($id_penyewa > 0) {
            mysqli_query($conn, "UPDATE Penyewa SET status_bayar = 'Lunas', jatuh_tempo = DATE_ADD(jatuh_tempo, INTERVAL 1 MONTH) WHERE id_penyewa = $id_penyewa");
            $notif_sukses = "Status tagihan penyewa diperbarui menjadi Lunas.";
        }
    }

    if ($aksi === 'hapus_penyewa') {
        $id_penyewa = (int)($_POST['id_penyewa'] ?? 0);
        if ($id_penyewa > 0) {
            mysqli_query($conn, "DELETE FROM Penyewa WHERE id_penyewa = $id_penyewa");
            $notif_sukses = "Data kontrak penyewa telah dihapus.";
        }
    }
}

// Ambil seluruh data penyewa untuk properti aktif
$daftar_penyewa = [];
$jml_aktif      = 0;
$jml_lunas      = 0;
$jml_jatuh_tempo = 0;
$jml_menunggak  = 0;

if ($selected_properti_id > 0) {
    $q_pny = mysqli_query($conn, "SELECT * FROM Penyewa WHERE id_properti = $selected_properti_id ORDER BY id_penyewa DESC");
    if ($q_pny) {
        while ($row = mysqli_fetch_assoc($q_pny)) {
            $daftar_penyewa[] = $row;
            $jml_aktif++;
            $sb = strtolower(trim($row['status_bayar']));
            if ($sb === 'lunas') {
                $jml_lunas++;
            } elseif ($sb === 'menunggak' || $sb === 'terlambat') {
                $jml_menunggak++;
            } else {
                $jml_jatuh_tempo++;
            }
        }
    }
}

$persen_okupansi = $total_kamar_prop > 0 ? round(($jml_aktif / $total_kamar_prop) * 100) : 0;
$persen_tertib   = $jml_aktif > 0 ? round(($jml_lunas / $jml_aktif) * 100) : 100;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar & Pengawasan Penyewa Kos - KosLink</title>
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

    <!-- SIDEBAR KIRI (Tanpa Badge Notifikasi Dummy) -->
    <aside class="w-64 bg-white border-r border-slate-200/80 flex flex-col justify-between fixed inset-y-0 left-0 z-40 hidden lg:flex">
        <div>
            <!-- Logo Brand -->
            <div class="px-6 py-5 flex items-center gap-3 border-b border-slate-100">
                <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-sm shadow-blue-200">
                    <i class="fa-solid fa-building text-base"></i>
                </div>
                <div>
                    <h1 class="font-extrabold text-slate-900 text-lg leading-none tracking-tight">KosLink</h1>
                    <p class="text-[10px] font-bold text-slate-400 tracking-widest uppercase mt-1">MITRA PEMILIK</p>
                </div>
            </div>

            <!-- Menu Utama -->
            <div class="px-4 py-6">
                <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">MENU UTAMA</p>
                <nav class="space-y-1.5">
                    <a href="dashboard.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-table-cells-large w-4 text-center text-slate-400"></i>
                        <span>Dashboard Overview</span>
                    </a>
                    <a href="kelola_penyewa.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <i class="fa-solid fa-users w-4 text-center"></i>
                        <span>Daftar & Awasi Penyewa</span>
                    </a>
                    <a href="admin_keluhan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-headset w-4 text-center text-slate-400"></i>
                        <span>Kelola Keluhan & Komplain</span>
                    </a>
                    <a href="admin_laporan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-chart-line w-4 text-center text-slate-400"></i>
                        <span>Laporan Penghasilan & Rekber</span>
                    </a>
                </nav>
            </div>
        </div>

        <!-- Bantuan Mitra & Logout -->
        <div class="p-4 space-y-3">
            <div class="bg-slate-50 border border-slate-200/70 rounded-2xl p-4">
                <div class="flex items-center gap-2 text-blue-600 font-bold text-xs mb-1.5">
                    <i class="fa-solid fa-circle-question"></i>
                    <span>Bantuan Mitra</span>
                </div>
                <p class="text-[11px] text-slate-500 leading-relaxed mb-3">Pusat resolusi kendala operasional & klaim garansi kos.</p>
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

        <!-- TOPBAR (Tanpa Lonceng Notifikasi) -->
        <header class="bg-white border-b border-slate-200/80 px-6 lg:px-8 py-3.5 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-30">
            <div class="flex items-center gap-3 flex-wrap">
                <form method="GET" action="" class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5">
                    <i class="fa-solid fa-house-chimney text-blue-600 text-xs mr-2.5"></i>
                    <div class="mr-2">
                        <p class="text-[9px] font-bold text-slate-400 uppercase leading-none">Properti Aktif</p>
                        <select name="properti" onchange="this.form.submit()" class="bg-transparent text-xs font-bold text-slate-800 focus:outline-none cursor-pointer pr-2">
                            <?php if(count($daftar_properti) > 0): ?>
                                <?php foreach($daftar_properti as $prop): ?>
                                    <option value="<?= $prop[$pk_prop] ?>" <?= $prop[$pk_prop] == $selected_properti_id ? 'selected' : '' ?>>
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
                </div>
                <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 border border-blue-200 flex items-center justify-center font-bold text-sm">
                    <i class="fa-regular fa-user"></i>
                </div>
            </div>
        </header>

        <!-- BODY HALAMAN -->
        <main class="p-6 lg:p-8 max-w-[1400px] w-full mx-auto space-y-6">

            <?php if($notif_sukses): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3 text-xs font-bold">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
                    <span><?= htmlspecialchars($notif_sukses) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <?php endif; ?>

            <?php if($notif_error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3 text-xs font-bold">
                    <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
                    <span><?= htmlspecialchars($notif_error) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700"><i class="fa-solid fa-xmark"></i></button>
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
                    <button onclick="window.print()" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-3.5 py-2.5 rounded-xl text-xs font-bold transition shadow-sm">
                        <i class="fa-solid fa-download text-slate-500"></i> Download Laporan (PDF/Excel)
                    </button>
                    <button onclick="bukaModal('modal-tambah-penyewa')" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition shadow-sm shadow-blue-200">
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
                            <?php if(count($daftar_penyewa) === 0): ?>
                            <tr>
                                <td colspan="6" class="py-16 px-6 text-center">
                                    <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-xl mx-auto mb-3">
                                        <i class="fa-solid fa-user-group"></i>
                                    </div>
                                    <h3 class="text-sm font-extrabold text-slate-900">Belum Ada Penyewa Terdaftar</h3>
                                    <p class="text-xs text-slate-400 mt-1 mb-5">Penyewa yang melakukan booking atau didaftarkan manual akan muncul di tabel ini.</p>
                                    <button onclick="bukaModal('modal-tambah-penyewa')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition shadow-sm no-print">
                                        + Daftarkan Penyewa Manual
                                    </button>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($daftar_penyewa as $pny): 
                                    $sb = strtolower(trim($pny['status_bayar']));
                                    $hp_clean = preg_replace('/[^0-9]/', '', $pny['no_hp']);
                                    if (substr($hp_clean, 0, 1) === '0') $hp_clean = '62' . substr($hp_clean, 1);
                                ?>
                                <tr class="baris-penyewa hover:bg-slate-50/70 transition"
                                    data-search="<?= htmlspecialchars(strtolower($pny['nama_penyewa'] . ' ' . $pny['no_kamar'])) ?>"
                                    data-status="<?= htmlspecialchars($sb) ?>"
                                    data-skema="<?= htmlspecialchars(strtolower($pny['skema_kontrak'])) ?>">
                                    <td class="py-4 px-6">
                                        <span class="inline-block bg-blue-50 text-blue-700 font-extrabold text-[10px] px-2 py-0.5 rounded-md mb-1">
                                            <?= htmlspecialchars($pny['no_kamar']) ?>
                                        </span>
                                        <p class="font-extrabold text-slate-900"><?= htmlspecialchars($pny['nama_penyewa']) ?></p>
                                        <p class="text-[11px] text-slate-400"><?= htmlspecialchars($pny['pekerjaan']) ?></p>
                                    </td>
                                    <td class="py-4 px-4">
                                        <p class="font-bold text-slate-800"><?= htmlspecialchars($pny['skema_kontrak']) ?></p>
                                        <p class="text-[11px] text-slate-400">Masuk: <?= date('d M Y', strtotime($pny['tanggal_masuk'])) ?></p>
                                    </td>
                                    <td class="py-4 px-4">
                                        <p class="font-extrabold text-slate-900">Rp <?= number_format($pny['nominal_sewa'], 0, ',', '.') ?></p>
                                        <p class="text-[11px] text-slate-500">Jatuh Tempo: <?= date('d M Y', strtotime($pny['jatuh_tempo'])) ?></p>
                                    </td>
                                    <td class="py-4 px-4">
                                        <?php if($sb === 'lunas'): ?>
                                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                                <i class="fa-solid fa-check text-[9px]"></i> Lunas
                                            </span>
                                        <?php elseif($sb === 'menunggak'): ?>
                                            <span class="inline-flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                                <i class="fa-solid fa-triangle-exclamation text-[9px]"></i> Menunggak
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                                <i class="fa-regular fa-clock text-[9px]"></i> Jatuh Tempo
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold px-2.5 py-1 rounded-lg">
                                            <i class="fa-solid fa-shield-halved text-[9px]"></i> <?= htmlspecialchars($pny['status_escrow']) ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-right space-x-1.5 no-print">
                                        <?php if($hp_clean): ?>
                                        <a href="https://wa.me/<?= $hp_clean ?>" target="_blank" title="WhatsApp Penyewa" class="inline-flex items-center justify-center w-8 h-8 rounded-xl border border-slate-200 text-emerald-600 hover:bg-emerald-50 transition">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if($sb !== 'lunas'): ?>
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="aksi" value="tandai_lunas">
                                            <input type="hidden" name="properti" value="<?= $selected_properti_id ?>">
                                            <input type="hidden" name="id_penyewa" value="<?= $pny['id_penyewa'] ?>">
                                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-bold px-2.5 py-1.5 rounded-xl transition">
                                                Tandai Lunas
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Hapus data kontrak penyewa ini?')">
                                            <input type="hidden" name="aksi" value="hapus_penyewa">
                                            <input type="hidden" name="properti" value="<?= $selected_properti_id ?>">
                                            <input type="hidden" name="id_penyewa" value="<?= $pny['id_penyewa'] ?>">
                                            <button type="submit" title="Hapus" class="inline-flex items-center justify-center w-8 h-8 rounded-xl border border-slate-200 text-rose-500 hover:bg-rose-50 transition">
                                                <i class="fa-regular fa-trash-can"></i>
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
    <div id="modal-tambah-penyewa" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-xl border border-slate-100">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Daftarkan Penyewa Manual</h3>
                    <p class="text-[11px] text-slate-400">Catat data kontrak penghuni baru ke sistem pengawasan kos</p>
                </div>
                <button onclick="tutupModal('modal-tambah-penyewa')" class="w-8 h-8 rounded-full bg-slate-50 text-slate-400 hover:text-slate-600 flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="aksi" value="tambah_penyewa">
                <input type="hidden" name="properti" value="<?= $selected_properti_id ?>">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Nama Lengkap Penyewa <span class="text-rose-500">*</span></label>
                        <input type="text" name="nama_penyewa" required placeholder="Nama penghuni" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Nomor Kamar <span class="text-rose-500">*</span></label>
                        <?php if(count($daftar_kamar) > 0): ?>
                            <select name="no_kamar" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold bg-white">
                                <?php foreach($daftar_kamar as $km): ?>
                                    <option value="<?= htmlspecialchars($km['label']) ?>"><?= htmlspecialchars($km['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="no_kamar" required placeholder="Contoh: Kamar 101" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Nomor WhatsApp</label>
                        <input type="text" name="no_hp" placeholder="0812xxxxxxxx" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Profesi / Kampus</label>
                        <input type="text" name="pekerjaan" placeholder="Contoh: Mahasiswa Unud" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Skema Kontrak</label>
                        <select name="skema_kontrak" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold bg-white">
                            <option value="Bulanan">Bulanan</option>
                            <option value="Per 3 Bulan">Per 3 Bulan</option>
                            <option value="Tahunan">Tahunan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Nominal Sewa (Rp)</label>
                        <input type="number" name="nominal_sewa" required placeholder="1500000" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Tanggal Masuk</label>
                        <input type="date" name="tanggal_masuk" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Jatuh Tempo</label>
                        <input type="date" name="jatuh_tempo" value="<?= date('Y-m-d', strtotime('+1 month')) ?>" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Status Tagihan</label>
                        <select name="status_bayar" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold bg-white">
                            <option value="Lunas">Lunas</option>
                            <option value="Jatuh Tempo">Jatuh Tempo</option>
                            <option value="Menunggak">Menunggak</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-2.5 pt-2">
                    <button type="button" onclick="tutupModal('modal-tambah-penyewa')" class="flex-1 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">Batal</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold shadow-sm">Simpan Penyewa</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterPenyewa() {
            const q = document.getElementById('search-penyewa').value.toLowerCase().trim();
            const st = document.getElementById('filter-status-tagihan').value;
            const sk = document.getElementById('filter-skema').value;

            document.querySelectorAll('.baris-penyewa').forEach(row => {
                const rSearch = row.getAttribute('data-search') || '';
                const rStatus = row.getAttribute('data-status') || '';
                const rSkema  = row.getAttribute('data-skema') || '';

                const matchSearch = (!q || rSearch.includes(q));
                const matchStatus = (st === 'semua' || rStatus.includes(st));
                const matchSkema  = (sk === 'semua' || rSkema.includes(sk));

                row.style.display = (matchSearch && matchStatus && matchSkema) ? '' : 'none';
            });
        }

        function resetFilterPenyewa() {
            document.getElementById('search-penyewa').value = '';
            document.getElementById('filter-status-tagihan').value = 'semua';
            document.getElementById('filter-skema').value = 'semua';
            filterPenyewa();
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
    </script>
</body>
</html>