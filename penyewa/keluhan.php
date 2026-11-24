<?php
require '../koneksi.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] === 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

$nama_user     = $_SESSION['nama'] ?? 'Penghuni KosLink';
$nama_user_esc = mysqli_real_escape_string($conn, $nama_user);
$sukses_msg    = '';
$error_msg     = '';

// 1. Pastikan tabel Keluhan tersedia & lengkapi kolom yang belum ada secara aman
// 1. Pastikan tabel keluhan tersedia & lengkapi kolom yang belum ada secara aman
$pk_col = 'id_keluhan';
try {
    $existing_cols = [];
    $res_cols = mysqli_query($conn, "SHOW COLUMNS FROM `keluhan`");
    if ($res_cols) {
        while ($c = mysqli_fetch_assoc($res_cols)) {
            $existing_cols[] = strtolower($c['Field']);
        }
    }

    if (!in_array('id_keluhan', $existing_cols) && in_array('id', $existing_cols)) {
        $pk_col = 'id';
    }

    $kolom_wajib = [
        'nama_penyewa'    => "VARCHAR(100) NOT NULL DEFAULT 'Penghuni'",
        'kamar'           => "VARCHAR(100) DEFAULT 'Kamar 204'",
        'nama_kos'        => "VARCHAR(150) DEFAULT 'Kos Udayana Exclusive Residence'",
        'kategori'        => "VARCHAR(100) NOT NULL DEFAULT 'AC & Pendingin'",
        'judul'           => "VARCHAR(150) NOT NULL DEFAULT ''",
        'deskripsi'       => "TEXT NULL",
        'urgensi'         => "VARCHAR(50) DEFAULT 'Darurat < 4 Jam'",
        'waktu_kunjungan' => "VARCHAR(100) DEFAULT 'Hari Ini (14.00 - 17.00)'",
        'foto_bukti'      => "VARCHAR(255) DEFAULT NULL",
        'status'          => "VARCHAR(50) DEFAULT 'Menunggu Respon Mitra'",
        'catatan_mitra'   => "TEXT DEFAULT NULL",
        'created_at'      => "DATETIME DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($kolom_wajib as $col => $def) {
        if (!in_array($col, $existing_cols)) {
            mysqli_query($conn, "ALTER TABLE `keluhan` ADD COLUMN `$col` $def");
        }
    }
} catch (\Throwable $e) {
    $error_msg = "Penyesuaian struktur database: " . $e->getMessage();
}


// 2. Ambil data kamar penyewa jika terdaftar di tabel penyewa (huruf kecil)
$kamar_user  = 'Kamar 204';
$kos_user    = 'Kos Udayana Exclusive Residence';
$id_pengguna = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? 0);

$q_penyewa = @mysqli_query(
    $conn,
    "SELECT p.*, pr.nama_kos
     FROM penyewa p
     LEFT JOIN properti_kos pr ON pr.id_properti = p.id_properti
     WHERE " . ($id_pengguna > 0 ? "p.id_pengguna = $id_pengguna OR " : "") . "p.nama_penyewa LIKE '%$nama_user_esc%'
     ORDER BY p.id_penyewa DESC LIMIT 1"
);
if ($q_penyewa && mysqli_num_rows($q_penyewa) > 0) {
    $row_p = mysqli_fetch_assoc($q_penyewa);
    if (!empty($row_p['no_kamar'])) {
        $kamar_user = $row_p['no_kamar'];
    }
    if (!empty($row_p['nama_kos'])) {
        $kos_user = $row_p['nama_kos'];
    }
}

// 3. Proses kirim keluhan baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_keluhan'])) {
    $kategori        = mysqli_real_escape_string($conn, trim($_POST['kategori'] ?? 'AC & Pendingin'));
    $judul           = mysqli_real_escape_string($conn, trim($_POST['judul'] ?? ''));
    $deskripsi       = mysqli_real_escape_string($conn, trim($_POST['deskripsi'] ?? ''));
    $urgensi         = mysqli_real_escape_string($conn, trim($_POST['urgensi'] ?? 'Darurat < 4 Jam'));
    $waktu_kunjungan = mysqli_real_escape_string($conn, trim($_POST['waktu_kunjungan'] ?? 'Hari Ini (14.00 - 17.00)'));
    $kamar_esc       = mysqli_real_escape_string($conn, $kamar_user);
    $kos_esc         = mysqli_real_escape_string($conn, $kos_user);

    $foto_path = null;
    if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/keluhan/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }
        $ext     = strtolower(pathinfo($_FILES['foto_bukti']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($ext, $allowed)) {
            $new_name = 'keluhan_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['foto_bukti']['tmp_name'], $upload_dir . $new_name)) {
                $foto_path = 'uploads/keluhan/' . $new_name;
            }
        }
    }
    $foto_sql = $foto_path ? "'" . mysqli_real_escape_string($conn, $foto_path) . "'" : "NULL";

    if ($judul !== '' && $deskripsi !== '') {
        try {
            $sql_insert = "INSERT INTO `keluhan` (`nama_penyewa`, `kamar`, `nama_kos`, `kategori`, `judul`, `deskripsi`, `urgensi`, `waktu_kunjungan`, `foto_bukti`, `status`)
                           VALUES ('$nama_user_esc', '$kamar_esc', '$kos_esc', '$kategori', '$judul', '$deskripsi', '$urgensi', '$waktu_kunjungan', $foto_sql, 'Menunggu Respon Mitra')";
            if (mysqli_query($conn, $sql_insert)) {
                $sukses_msg = "Tiket keluhan berhasil dikirim ke Mitra Pemilik Kos dan langsung tampil di dashboard Mitra!";
            }
        } catch (\Throwable $e) {
            $error_msg = "Gagal menyimpan keluhan: " . $e->getMessage();
        }
    } else {
        $error_msg = "Judul dan deskripsi keluhan wajib diisi.";
    }
}

// 4. Ambil seluruh keluhan milik penyewa ini dari database
$daftar_keluhan = [];
$jumlah_aktif   = 0;
try {
    $q_keluhan = mysqli_query($conn, "SELECT * FROM `keluhan` WHERE `nama_penyewa` = '$nama_user_esc' ORDER BY `$pk_col` DESC");
    if ($q_keluhan) {
        while ($row = mysqli_fetch_assoc($q_keluhan)) {
            $daftar_keluhan[] = $row;
            if (($row['status'] ?? '') !== 'Selesai') {
                $jumlah_aktif++;
            }
        }
    }
} catch (\Throwable $e) {
    // Abaikan jika gagal ambil
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pusat Keluhan &amp; Lapor Kerusakan - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- NAVBAR TOP SERAGAM -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-8">
                <a href="../index.php" class="flex items-center gap-2.5 shrink-0">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                        <i class="fa-solid fa-building text-sm"></i>
                    </div>
                    <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
                </a>
                <nav class="hidden md:flex items-center gap-6 text-xs font-bold">
                    <a href="dashboard.php" class="text-slate-500 hover:text-slate-900 py-5 transition">Beranda</a>
                    <a href="bayar.php" class="text-slate-500 hover:text-slate-900 py-5 transition">Tagihan &amp; Pembayaran</a>
                    <a href="keluhan.php" class="text-blue-600 border-b-2 border-blue-600 py-5 flex items-center gap-1.5">
                        Keluhan &amp; Bantuan
                        <?php if ($jumlah_aktif > 0): ?>
                        <span class="bg-amber-100 text-amber-800 text-[10px] px-2 py-0.5 rounded-full font-extrabold"><?= $jumlah_aktif ?> Aktif</span>
                        <?php endif; ?>
                    </a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <button type="button" class="w-9 h-9 rounded-full bg-slate-50 hover:bg-slate-100 border border-slate-200/80 text-slate-600 flex items-center justify-center text-xs relative transition">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($jumlah_aktif > 0): ?>
                    <span class="w-2 h-2 rounded-full bg-rose-500 absolute top-2 right-2"></span>
                    <?php endif; ?>
                </button>
                <div class="flex items-center gap-2.5 pl-3 border-l border-slate-200">
                    <div class="w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs shadow-xs">
                        <i class="fa-regular fa-user"></i>
                    </div>
                    <div class="hidden sm:block text-left">
                        <p class="text-xs font-extrabold text-slate-900 leading-tight"><?= htmlspecialchars($nama_user) ?></p>
                        <p class="text-[10px] text-slate-400 font-medium">Penyewa Aktif &bull; <?= htmlspecialchars($kamar_user) ?></p>
                    </div>
                    <a href="../auth/logout.php" title="Keluar" class="ml-1 text-slate-400 hover:text-rose-600 text-xs transition">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-7 space-y-6 flex-1">

        <!-- BREADCRUMB & SLA PILL -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <nav class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5">
                <a href="dashboard.php" class="hover:text-blue-600">Beranda</a>
                <span>&gt;</span>
                <a href="dashboard.php#kos-saya" class="hover:text-blue-600">Kos Saya</a>
                <span>&gt;</span>
                <strong class="text-slate-700">Pusat Keluhan &amp; Lapor Kerusakan Fasilitas</strong>
            </nav>
            <span class="self-start sm:self-auto inline-flex items-center gap-1.5 bg-blue-50 text-blue-700 border border-blue-200 text-[11px] font-bold px-3 py-1 rounded-full">
                <i class="fa-solid fa-shield-halved"></i> Escrow Protection Garansi SLA 24-Jam Aktif
            </span>
        </div>

        <!-- HEADER HALAMAN -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-1">
                <p class="text-[10px] font-extrabold uppercase tracking-wider text-blue-600">RESOLUSI KENDALA FASILITAS KAMAR</p>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Pusat Keluhan &amp; Lapor Kerusakan Fasilitas</h1>
                <p class="text-xs text-slate-500">Ajukan perbaikan kamar secara resmi ke Mitra Pemilik (<?= htmlspecialchars($kos_user) ?>). Dilindungi oleh <a href="#proteksi" class="text-blue-600 font-bold hover:underline">Garansi SLA 24 Jam &amp; Escrow Rekber KosLink</a>.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                <a href="https://wa.me/6281234567890" target="_blank" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-bold shadow-2xs inline-flex items-center gap-2 transition">
                    <i class="fa-solid fa-phone-volume text-rose-500"></i> Kontak Darurat Penjaga (Pak Ketut)
                </a>
                <a href="#form-keluhan" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-extrabold shadow-sm inline-flex items-center gap-2 transition">
                    <i class="fa-solid fa-circle-plus"></i> Ajukan Keluhan Baru
                </a>
            </div>
        </div>

        <?php if ($sukses_msg): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-4 rounded-2xl text-xs font-bold flex items-center gap-2.5 shadow-2xs">
            <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
            <span><?= htmlspecialchars($sukses_msg) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-5 py-4 rounded-2xl text-xs font-bold flex items-center gap-2.5 shadow-2xs">
            <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
            <span><?= htmlspecialchars($error_msg) ?></span>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-7 items-start">
            
            <!-- KOLOM KIRI: FORMULIR LAPOR KELUHAN -->
            <div class="lg:col-span-8 space-y-6" id="form-keluhan">
                <form method="POST" enctype="multipart/form-data" class="bg-white border border-slate-200/80 rounded-3xl p-6 sm:p-7 shadow-sm space-y-6 text-xs">
                    
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center text-sm shadow-xs">
                                <i class="fa-solid fa-wrench"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h2 class="text-sm font-extrabold text-slate-900">Formulir Lapor Keluhan Kamar</h2>
                                    <span class="font-mono text-[10px] font-bold bg-slate-100 text-slate-600 px-2 py-0.5 rounded">#TKT-Baru</span>
                                </div>
                                <p class="text-[11px] text-slate-400">Laporan Anda langsung diteruskan ke dashboard Mitra Pemilik Kos</p>
                            </div>
                        </div>
                        <span class="self-start sm:self-auto inline-flex items-center gap-1.5 bg-blue-50 text-blue-700 text-[10px] font-extrabold px-3 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> Tiket Terproteksi Rekber
                        </span>
                    </div>

                    <!-- 1. KATEGORI FASILITAS -->
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between">
                            <label class="font-extrabold text-slate-900 flex items-center gap-2">
                                <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] flex items-center justify-center">1</span>
                                Pilih Kategori Fasilitas Bermasalah <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-slate-400 font-semibold">Wajib dipilih</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <?php
                            $kategori_list = [
                                ['AC & Pendingin', 'fa-snowflake', 'Bocor, tidak dingin, rembes'],
                                ['Kelistrikan & Lampu', 'fa-bolt', 'Stopkontak, saklar, MCB'],
                                ['Saluran Air & Sanitasi', 'fa-droplet', 'Kran mampet, wastafel, WC'],
                                ['Pintu, Jendela & Kunci', 'fa-key', 'Engsel, kunci macet, tralis'],
                                ['Internet / WiFi', 'fa-wifi', 'Sinyal lemah, router drop'],
                                ['Kebersihan & Lainnya', 'fa-broom', 'Sampah, kebisingan, perabot']
                            ];
                            foreach ($kategori_list as $idx => $kat):
                            ?>
                            <label class="cursor-pointer block">
                                <input type="radio" name="kategori" value="<?= $kat[0] ?>" <?= $idx === 0 ? 'checked' : '' ?> class="peer hidden">
                                <div class="p-3.5 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-blue-600 peer-checked:bg-blue-50/40 hover:border-blue-300 space-y-1.5 transition">
                                    <div class="w-7 h-7 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center text-xs"><i class="fa-solid <?= $kat[1] ?>"></i></div>
                                    <p class="font-extrabold text-slate-900"><?= $kat[0] ?></p>
                                    <p class="text-[10px] text-slate-500"><?= $kat[2] ?></p>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 2. LOKASI UNIT -->
                    <div class="space-y-2">
                        <label class="font-extrabold text-slate-900 flex items-center gap-2">
                            <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] flex items-center justify-center">2</span>
                            Lokasi &amp; Unit Terdaftar Anda
                        </label>
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><i class="fa-solid fa-building"></i></div>
                                <div>
                                    <p class="font-extrabold text-slate-900"><?= htmlspecialchars($kamar_user) ?></p>
                                    <p class="text-[11px] text-slate-500"><?= htmlspecialchars($kos_user) ?></p>
                                </div>
                            </div>
                            <span class="self-start sm:self-auto bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-extrabold px-2.5 py-1 rounded-full">
                                <i class="fa-solid fa-circle-check mr-1"></i> Unit Terverifikasi
                            </span>
                        </div>
                    </div>

                    <!-- 3. JUDUL & DESKRIPSI -->
                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <label class="font-extrabold text-slate-900 flex items-center gap-2">
                                <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] flex items-center justify-center">3</span>
                                Judul Singkat Kendala <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="judul" placeholder="Contoh: AC kamar tidak dingin atau air menetes..." required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-800 placeholder:font-normal placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="font-extrabold text-slate-900">Deskripsi Rinci Kerusakan <span class="text-rose-500">*</span></label>
                                <span class="text-[10px] text-slate-400">Jelaskan kronologi &amp; dampaknya</span>
                            </div>
                            <textarea name="deskripsi" rows="3" placeholder="Tuliskan detail kerusakan fasilitas yang dialami agar teknisi dapat menyiapkan peralatan yang sesuai..." required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-medium text-slate-700 placeholder:text-slate-400 leading-relaxed focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                    </div>

                    <!-- 4. TINGKAT URGENSI SLA -->
                    <div class="space-y-2.5">
                        <label class="font-extrabold text-slate-900 flex items-center gap-2">
                            <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] flex items-center justify-center">4</span>
                            Tingkat Urgensi &amp; Garansi Respon SLA <span class="text-rose-500">*</span>
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <label class="cursor-pointer block">
                                <input type="radio" name="urgensi" value="Darurat < 4 Jam" checked class="peer hidden">
                                <div class="p-3.5 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-rose-500 peer-checked:bg-rose-50/40 space-y-1 transition">
                                    <span class="inline-block bg-rose-600 text-white text-[9px] font-extrabold px-2 py-0.5 rounded-full">&bull; Darurat &bull; &lt; 4 Jam</span>
                                    <p class="font-extrabold text-slate-900 pt-0.5">Mendesak / Darurat</p>
                                    <p class="text-[10px] text-slate-500 leading-relaxed">Fasilitas vital bocor parah / berpotensi merusak perabotan atau bahaya listrik.</p>
                                </div>
                            </label>
                            <label class="cursor-pointer block">
                                <input type="radio" name="urgensi" value="Sedang < 12 Jam" class="peer hidden">
                                <div class="p-3.5 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-amber-500 peer-checked:bg-amber-50/40 space-y-1 transition">
                                    <span class="inline-block bg-amber-100 text-amber-800 text-[9px] font-extrabold px-2 py-0.5 rounded-full">Sedang &bull; &lt; 12 Jam</span>
                                    <p class="font-extrabold text-slate-900 pt-0.5">Standar Prioritas</p>
                                    <p class="text-[10px] text-slate-400 leading-relaxed">Mengganggu kenyamanan harian tapi kamar masih bisa dihuni aman.</p>
                                </div>
                            </label>
                            <label class="cursor-pointer block">
                                <input type="radio" name="urgensi" value="Rutin < 24 Jam" class="peer hidden">
                                <div class="p-3.5 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-blue-600 peer-checked:bg-blue-50/40 space-y-1 transition">
                                    <span class="inline-block bg-slate-100 text-slate-600 text-[9px] font-extrabold px-2 py-0.5 rounded-full">Rutin &bull; &lt; 24 Jam</span>
                                    <p class="font-extrabold text-slate-900 pt-0.5">Keluhan Rutin</p>
                                    <p class="text-[10px] text-slate-400 leading-relaxed">Pengecekan berkala, penggantian bohlam non-utama, atau cat tembok.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- 5. UNGGAH BUKTI FOTO -->
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between">
                            <label class="font-extrabold text-slate-900 flex items-center gap-2">
                                <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] flex items-center justify-center">5</span>
                                Unggah Bukti Foto Kerusakan (Opsional)
                            </label>
                            <span id="file-status" class="text-[10px] text-slate-400 font-semibold">Belum ada foto dipilih</span>
                        </div>
                        <label class="rounded-2xl border-2 border-dashed border-slate-200 hover:border-blue-400 bg-slate-50/50 flex flex-col items-center justify-center p-5 text-center cursor-pointer transition">
                            <input type="file" name="foto_bukti" id="input-foto-bukti" class="hidden" accept="image/*" onchange="previewFoto(this)">
                            <img id="img-preview" src="" alt="Preview" class="hidden max-h-40 rounded-xl mb-3 shadow-xs border border-slate-200">
                            <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mb-1.5"><i class="fa-solid fa-camera"></i></div>
                            <p class="font-extrabold text-slate-800 text-[11px]">Klik untuk Unggah Foto Kerusakan</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">Format JPG, PNG, WEBP (Maks 5MB)</p>
                        </label>
                    </div>

                    <!-- 6. WAKTU KUNJUNGAN -->
                    <div class="space-y-2.5">
                        <label class="font-extrabold text-slate-900 flex items-center gap-2">
                            <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] flex items-center justify-center">6</span>
                            Pilih Waktu Kunjungan Teknisi / Penjaga Kos
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                            <label class="cursor-pointer block">
                                <input type="radio" name="waktu_kunjungan" value="Hari Ini (14.00 - 17.00)" checked class="peer hidden">
                                <div class="p-3 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-blue-600 peer-checked:bg-blue-50/30 flex items-center gap-2.5 transition">
                                    <i class="fa-regular fa-calendar-check text-blue-600"></i>
                                    <div>
                                        <p class="font-extrabold text-slate-900 text-[11px]">Hari Ini (14.00 - 17.00)</p>
                                        <p class="text-[10px] text-blue-600 font-semibold">Saya Berada di Kamar</p>
                                    </div>
                                </div>
                            </label>
                            <label class="cursor-pointer block">
                                <input type="radio" name="waktu_kunjungan" value="Besok (09.00 - 12.00)" class="peer hidden">
                                <div class="p-3 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-blue-600 peer-checked:bg-blue-50/30 flex items-center gap-2.5 transition">
                                    <i class="fa-regular fa-clock text-slate-400"></i>
                                    <div>
                                        <p class="font-extrabold text-slate-800 text-[11px]">Besok (09.00 - 12.00)</p>
                                        <p class="text-[10px] text-slate-400">Sesi Pagi</p>
                                    </div>
                                </div>
                            </label>
                            <label class="cursor-pointer block">
                                <input type="radio" name="waktu_kunjungan" value="Kapan Saja (Izinkan Buka Pintu)" class="peer hidden">
                                <div class="p-3 rounded-2xl border border-slate-200 peer-checked:border-2 peer-checked:border-blue-600 peer-checked:bg-blue-50/30 flex items-center gap-2.5 transition">
                                    <i class="fa-solid fa-key text-slate-400"></i>
                                    <div>
                                        <p class="font-extrabold text-slate-800 text-[11px]">Kapan Saja</p>
                                        <p class="text-[10px] text-slate-400">Izinkan Penjaga Buka Pintu</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- TOMBOL KIRIM -->
                    <div class="pt-3 border-t border-slate-100 space-y-2.5">
                        <div class="flex flex-col sm:flex-row items-center gap-3">
                            <button type="submit" name="kirim_keluhan" value="1" class="w-full sm:flex-1 bg-blue-600 hover:bg-blue-700 text-white font-extrabold py-3.5 px-6 rounded-xl transition shadow-md flex items-center justify-center gap-2">
                                <i class="fa-solid fa-circle-check"></i> Kirim Keluhan ke Mitra &amp; Aktifkan SLA Garansi
                            </button>
                            <button type="reset" class="w-full sm:w-auto bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3.5 px-5 rounded-xl transition">
                                Reset Form
                            </button>
                        </div>
                        <p class="text-[10px] text-slate-400 text-center">Dengan mengirim laporan ini, tiket keluhan akan langsung tercatat di sistem dan muncul di dashboard Mitra Pemilik Kos.</p>
                    </div>

                </form>
            </div>

            <!-- KOLOM KANAN: PROTEKSI & DAFTAR KELUHAN REAL-TIME -->
            <div class="lg:col-span-4 space-y-6" id="proteksi">
                
                <!-- JAMINAN 100% PROTEKSI -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                    <div class="flex items-center gap-2.5 border-b border-slate-100 pb-3.5">
                        <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-shield-halved"></i></div>
                        <div>
                            <h3 class="font-extrabold text-slate-900">Jaminan 100% Proteksi</h3>
                            <p class="text-[10px] text-blue-600 font-bold">Hak Penuh &amp; Keamanan Penyewa</p>
                        </div>
                    </div>
                    <div class="space-y-3.5 text-slate-600">
                        <div class="flex items-start gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-blue-50 text-blue-600 font-extrabold text-[10px] flex items-center justify-center shrink-0 mt-0.5">1</span>
                            <p><strong class="text-slate-900">Respon Cepat Terjamin:</strong> Mitra pemilik wajib memberikan solusi atau jadwal teknisi sesuai batas waktu tiket SLA.</p>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-blue-50 text-blue-600 font-extrabold text-[10px] flex items-center justify-center shrink-0 mt-0.5">2</span>
                            <p><strong class="text-slate-900">Hold Escrow Otomatis:</strong> Jika komplain darurat diabaikan &gt;24 jam, pencairan dana sewa bulanan ke mitra ditahan di escrow KosLink.</p>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-blue-50 text-blue-600 font-extrabold text-[10px] flex items-center justify-center shrink-0 mt-0.5">3</span>
                            <p><strong class="text-slate-900">Teknisi Rekanan Gratis:</strong> Jika mitra berhalangan hadir, KosLink mengirim teknisi resmi rekanan tanpa membebani biaya sepeser pun.</p>
                        </div>
                    </div>
                </div>

                <!-- KELUHAN ANDA (DINAMIS DARI DATABASE) -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3.5">
                        <div class="flex items-center gap-2">
                            <h3 class="font-extrabold text-slate-900">Keluhan Anda</h3>
                            <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] font-extrabold flex items-center justify-center"><?= count($daftar_keluhan) ?></span>
                        </div>
                        <span class="text-[11px] font-semibold text-slate-400">Real-time Sync</span>
                    </div>

                    <?php if (empty($daftar_keluhan)): ?>
                        <div class="p-6 rounded-2xl bg-slate-50 border border-dashed border-slate-200 text-center space-y-1.5">
                            <div class="w-10 h-10 rounded-full bg-white border border-slate-200 text-slate-400 flex items-center justify-center mx-auto">
                                <i class="fa-regular fa-folder-open"></i>
                            </div>
                            <p class="font-extrabold text-slate-700">Belum Ada Riwayat Keluhan</p>
                            <p class="text-[11px] text-slate-400">Keluhan yang Anda ajukan melalui formulir akan tampil di sini dan langsung diteruskan ke Mitra Pemilik Kos.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($daftar_keluhan as $item): 
                                $id_val     = $item[$pk_col] ?? 1;
                                $tgl_buat   = !empty($item['created_at']) ? strtotime($item['created_at']) : time();
                                $is_selesai = (($item['status'] ?? '') === 'Selesai');
                                $is_proses  = (($item['status'] ?? '') === 'Diproses Teknisi');
                                $card_bg    = $is_selesai ? 'bg-emerald-50/50 border-emerald-200/80' : ($is_proses ? 'bg-blue-50/50 border-blue-200/80' : 'bg-amber-50/70 border-amber-200/80');
                                $badge_cls  = $is_selesai ? 'bg-emerald-100 text-emerald-800' : ($is_proses ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800');
                                $kode_tiket = '#TKT-' . date('Ymd', $tgl_buat) . '-' . str_pad($id_val, 3, '0', STR_PAD_LEFT);
                            ?>
                            <div class="p-4 rounded-2xl border <?= $card_bg ?> space-y-2">
                                <div class="flex items-center justify-between gap-1 flex-wrap">
                                    <span class="font-mono text-[10px] font-bold text-slate-500"><?= $kode_tiket ?></span>
                                    <span class="<?= $badge_cls ?> text-[9px] font-extrabold px-2 py-0.5 rounded"><?= htmlspecialchars($item['status'] ?? 'Menunggu Respon Mitra') ?></span>
                                </div>
                                <div>
                                    <p class="font-extrabold text-slate-900"><?= htmlspecialchars($item['judul'] ?? '') ?></p>
                                    <p class="text-[11px] text-slate-500"><?= htmlspecialchars($item['kamar'] ?? 'Kamar 204') ?> &bull; <?= htmlspecialchars($item['kategori'] ?? '') ?> &bull; <?= date('d M Y H:i', $tgl_buat) ?></p>
                                </div>
                                <?php if (!empty($item['catatan_mitra'])): ?>
                                <div class="p-2.5 rounded-xl bg-white/80 border border-slate-200/70 text-[11px] text-slate-700">
                                    <span class="font-extrabold text-blue-600 block text-[10px]">Respon Mitra Pemilik:</span>
                                    <?= htmlspecialchars($item['catatan_mitra']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PENJAGA KOS STANDBY -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                    <div class="flex items-center justify-between">
                        <h3 class="font-extrabold text-slate-900">Penjaga Kos Standby</h3>
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-extrabold text-sm shrink-0">KS</div>
                        <div>
                            <p class="font-extrabold text-slate-900">Pak Ketut Suardika</p>
                            <p class="text-[11px] text-blue-600 font-semibold">Kepala Penjaga Kos &amp; Teknisi Cepat</p>
                            <p class="text-[10px] text-slate-400">Siaga di Pos Gerbang Utama</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <a href="https://wa.me/6281234567890" target="_blank" class="bg-white hover:bg-emerald-50 text-emerald-700 border border-emerald-200 font-extrabold py-2.5 rounded-xl flex items-center justify-center gap-1.5 transition">
                            <i class="fa-brands fa-whatsapp text-sm"></i> WhatsApp
                        </a>
                        <a href="tel:+6281234567890" class="bg-blue-600 hover:bg-blue-700 text-white font-extrabold py-2.5 rounded-xl flex items-center justify-center gap-1.5 transition">
                            <i class="fa-solid fa-phone"></i> Telepon Siaga
                        </a>
                    </div>
                </div>

            </div>

        </div>

    </main>

    <!-- FOOTER SERAGAM -->
    <footer class="bg-white border-t border-slate-200/80 mt-12 text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 bg-blue-600 rounded-lg flex items-center justify-center text-white text-xs"><i class="fa-solid fa-building"></i></div>
                <span class="font-extrabold text-slate-900 text-sm">KosLink</span>
            </div>
            <div class="flex flex-wrap items-center gap-5 font-medium">
                <a href="#" class="hover:text-blue-600">Syarat &amp; Ketentuan</a>
                <a href="#" class="hover:text-blue-600">Kebijakan Privasi</a>
                <a href="#" class="hover:text-blue-600">Bantuan Penyewa</a>
                <a href="#" class="hover:text-blue-600">Kontak Pengelola</a>
            </div>
        </div>
        <div class="border-t border-slate-100">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-[11px] text-slate-400">
                <p>&copy; 2025 PT KosLink Digital Indonesia. Hak cipta dilindungi undang-undang.</p>
                <p class="inline-flex items-center gap-1.5 text-emerald-600 font-semibold"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Semua Sistem Berjalan Normal</p>
            </div>
        </div>
    </footer>

    <script>
        function previewFoto(input) {
            const preview = document.getElementById('img-preview');
            const status  = document.getElementById('file-status');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                };
                reader.readAsDataURL(input.files[0]);
                status.innerText = 'Terpilih: ' + input.files[0].name;
            }
        }
    </script>
</body>
</html>