<?php
require __DIR__ . '/../koneksi.php';

$role_sesi = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (empty($_SESSION['id_admin']) && !in_array($role_sesi, ['admin', 'mitra'], true)) {
    flash('error', 'Silakan masuk sebagai Super Admin terlebih dahulu.');
    header("Location: ../auth/login.php");
    exit;
}

$nama_admin = $_SESSION['nama'] ?? $_SESSION['nama_admin'] ?? 'Administrator';

function getColsTm($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) $cols[] = $r['Field'];
    }
    return $cols;
}

function uploadDocTm(string $fieldName, string $prefix): string {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $dir = __DIR__ . '/../uploads/mitra/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
        return '';
    }
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . $filename)) {
        return 'uploads/mitra/' . $filename;
    }
    return '';
}

$error_tm = '';

// --- HANDLER SIMPAN MITRA & PROPERTI (4 TAHAP: TANPA FASILITAS & TANPA INPUT KAMAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submit_mode = $_POST['submit_mode'] ?? 'verifikasi'; // 'draf' atau 'verifikasi'
    $status_awal = ($submit_mode === 'draf') ? 'pending' : 'aktif';
    $status_prop = ($submit_mode === 'draf') ? 'pending' : 'disetujui';

    // Tahap 1: Identitas Mitra
    $tipe_kemitraan = trim($_POST['partnership_type'] ?? 'Perorangan');
    $nama_mitra     = trim($_POST['nama_mitra'] ?? '');
    $nik            = trim($_POST['nik'] ?? '');
    $no_hp_raw      = trim($_POST['no_hp'] ?? '');
    $no_hp          = $no_hp_raw !== '' ? (strpos($no_hp_raw, '0') === 0 || strpos($no_hp_raw, '+') === 0 ? $no_hp_raw : '+62' . ltrim($no_hp_raw, '- ')) : '';
    $email          = trim($_POST['email'] ?? '');
    $password_plain = trim($_POST['password_mitra'] ?? 'mitra123');
    if ($password_plain === '') $password_plain = 'mitra123';

    // Tahap 2: Profil Properti Kos
    $nama_kos       = trim($_POST['nama_kos'] ?? '');
    $tipe_kos       = strtolower(trim($_POST['tipe_kos'] ?? 'putra'));
    $lokasi         = trim($_POST['lokasi'] ?? '');
    $alamat_lengkap = trim($_POST['alamat_lengkap'] ?? '');

    // Tahap 3: Legalitas
    $jenis_hak      = trim($_POST['jenis_hak'] ?? '');
    $no_sertifikat  = trim($_POST['no_sertifikat'] ?? '');
    $nop_pbb        = trim($_POST['nop_pbb'] ?? '');
    $nib            = trim($_POST['nib'] ?? '');

    // Tahap 4: Rekening Payout
    $nama_bank      = trim($_POST['nama_bank'] ?? '');
    $no_rekening    = trim($_POST['no_rekening'] ?? '');
    $nama_pemilik   = trim($_POST['nama_pemilik'] ?? $nama_mitra);
    $skema_komisi   = trim($_POST['commission_fee'] ?? '5.0%');

    if ($nama_mitra === '' || $email === '' || $nama_kos === '') {
        $error_tm = 'Nama lengkap pemilik, email resmi, dan nama properti kos wajib diisi.';
    } else {
        $cek_email = db_one("SELECT id_mitra FROM mitra WHERE email = ?", 's', [$email]);
        if ($cek_email) {
            $error_tm = 'Email mitra sudah terdaftar di sistem. Gunakan alamat email lain.';
        } else {
            $path_ktp = uploadDocTm('file_ktp', 'ktp');
            $path_shm = uploadDocTm('file_shm', 'legal');

            $conn->begin_transaction();
            try {
                // 1. Insert ke tabel mitra
                $cols_m = getColsTm($conn, 'mitra');
                $data_m = [
                    'nama_mitra'     => $nama_mitra,
                    'email'          => $email,
                    'password'       => password_hash($password_plain, PASSWORD_DEFAULT),
                    'no_hp'          => $no_hp,
                    'nik'            => $nik,
                    'tipe_kemitraan' => $tipe_kemitraan,
                    'foto_ktp'       => $path_ktp,
                    'status_mitra'   => $status_awal,
                ];
                $ins_cols_m = [];
                $ins_vals_m = [];
                $ins_type_m = '';
                foreach ($data_m as $col => $val) {
                    if (in_array($col, $cols_m, true)) {
                        $ins_cols_m[] = "`$col`";
                        $ins_vals_m[] = $val;
                        $ins_type_m  .= 's';
                    }
                }
                $ph_m = implode(',', array_fill(0, count($ins_cols_m), '?'));
                q("INSERT INTO mitra (" . implode(',', $ins_cols_m) . ") VALUES ($ph_m)", $ins_type_m, $ins_vals_m);
                $id_mitra_baru = (int)$conn->insert_id;

                // 2. Insert ke tabel properti_kos
                $cols_p = getColsTm($conn, 'properti_kos');
                $data_p = [
                    'id_mitra'          => $id_mitra_baru,
                    'nama_kos'          => $nama_kos,
                    'tipe_kos'          => $tipe_kos,
                    'lokasi'            => $lokasi,
                    'alamat'            => $alamat_lengkap,
                    'deskripsi'         => $alamat_lengkap,
                    'harga_mulai'       => 0,
                    'fasilitas'         => '',
                    'jenis_sertifikat'  => $jenis_hak,
                    'no_sertifikat'     => $no_sertifikat,
                    'nop_pbb'           => $nop_pbb,
                    'nib'               => $nib,
                    'dokumen_legal'     => $path_shm,
                    'status_verifikasi' => $status_prop,
                ];
                $ins_cols_p = [];
                $ins_vals_p = [];
                $ins_type_p = '';
                foreach ($data_p as $col => $val) {
                    if (in_array($col, $cols_p, true)) {
                        $ins_cols_p[] = "`$col`";
                        $ins_vals_p[] = $val;
                        $ins_type_p  .= is_int($val) ? 'i' : 's';
                    }
                }
                $ph_p = implode(',', array_fill(0, count($ins_cols_p), '?'));
                q("INSERT INTO properti_kos (" . implode(',', $ins_cols_p) . ") VALUES ($ph_p)", $ins_type_p, $ins_vals_p);

                // 3. Simpan rekening bank payout ke tabel rekening_mitra
                $cols_r = getColsTm($conn, 'rekening_mitra');
                if (!empty($cols_r) && $no_rekening !== '') {
                    $col_bank    = in_array('nama_bank', $cols_r, true) ? 'nama_bank' : 'bank';
                    $col_norek   = in_array('no_rekening', $cols_r, true) ? 'no_rekening' : 'nomor_rekening';
                    $col_pemilik = in_array('nama_pemilik', $cols_r, true) ? 'nama_pemilik' : 'atas_nama';
                    $data_r = [
                        'id_mitra'   => $id_mitra_baru,
                        $col_bank    => $nama_bank,
                        $col_norek   => $no_rekening,
                        $col_pemilik => $nama_pemilik,
                    ];
                    $ins_cols_r = [];
                    $ins_vals_r = [];
                    $ins_type_r = '';
                    foreach ($data_r as $col => $val) {
                        if (in_array($col, $cols_r, true)) {
                            $ins_cols_r[] = "`$col`";
                            $ins_vals_r[] = $val;
                            $ins_type_r  .= is_int($val) ? 'i' : 's';
                        }
                    }
                    $ph_r = implode(',', array_fill(0, count($ins_cols_r), '?'));
                    q("INSERT INTO rekening_mitra (" . implode(',', $ins_cols_r) . ") VALUES ($ph_r)", $ins_type_r, $ins_vals_r);
                }

                $conn->commit();
                $_SESSION['flash_km'] = ($submit_mode === 'draf')
                    ? "Draf pendaftaran Mitra {$nama_mitra} ({$nama_kos}) berhasil disimpan."
                    : "Mitra {$nama_mitra} & properti {$nama_kos} berhasil didaftarkan! Unit kamar & fasilitas dapat diatur oleh Mitra melalui akunnya (Password: {$password_plain}).";
                header("Location: kelola_mitra.php");
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $error_tm = 'Gagal menyimpan data mitra: ' . $e->getMessage();
            }
        }
    }
}

// --- DATA RINGKAS HEADER & SIDEBAR ---
$jml_mitra         = (int)(db_one("SELECT COUNT(*) AS c FROM mitra")['c'] ?? 0);
$cols_kamar_cek    = getColsTm($conn, 'kamar');
$jml_kamar_pending = in_array('status_validasi', $cols_kamar_cek, true)
    ? (int)(db_one("SELECT COUNT(*) AS c FROM kamar WHERE status_validasi = 'pending'")['c'] ?? 0)
    : 0;

$stat_trx = db_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status_pembayaran = 'lunas' THEN total_harga ELSE 0 END), 0) AS total_gmv,
        COUNT(CASE WHEN status_pembayaran = 'pending' THEN 1 END) AS trx_pending
    FROM transaksi
");
$escrow_pool = (float)($stat_trx['total_gmv'] ?? 0);
$trx_pending = (int)($stat_trx['trx_pending'] ?? 0);
$total_notif = $trx_pending + $jml_kamar_pending;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Tambah Mitra Baru - KosLink Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&amp;display=swap" rel="stylesheet">
<style>
  @layer base {
    html, body { margin: 0; padding: 0; }
    body { overscroll-behavior: none; }
    main > :first-child { margin-top: 0 !important; }
    main > :last-child { margin-bottom: 0 !important; }
  }
  ::-webkit-scrollbar { display: none; }
  @keyframes fadeSlide {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .step-animate { animation: fadeSlide 0.25s ease-out; }
</style>
<script src="https://cdn.tailwindcss.com"></script>
<script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "primary-container": "#0066ff", "on-primary": "#ffffff", "surface-container-high": "#dce9ff", "on-background": "#0b1c30", "on-tertiary-fixed-variant": "#832600", "on-tertiary": "#ffffff", "surface-dim": "#cbdbf5", "on-error-container": "#93000a", "primary-fixed": "#dae1ff", "on-primary-fixed": "#001849", "surface": "#f8f9ff", "background": "#f8f9ff", "primary": "#0050cb", "surface-container": "#e5eeff", "primary-fixed-dim": "#b3c5ff", "on-secondary-fixed": "#161c22", "surface-bright": "#f8f9ff", "on-tertiary-fixed": "#390c00", "on-primary-container": "#f8f7ff", "on-error": "#ffffff", "tertiary-fixed": "#ffdbd0", "surface-container-highest": "#d3e4fe", "secondary": "#585f66", "on-primary-fixed-variant": "#003fa4", "secondary-fixed": "#dde3ec", "inverse-surface": "#213145", "on-secondary-container": "#5e656d", "secondary-fixed-dim": "#c1c7d0", "tertiary-container": "#cc4204", "on-secondary": "#ffffff", "surface-container-low": "#eff4ff", "outline-variant": "#c2c6d8", "on-tertiary-container": "#fff6f4", "surface-tint": "#0054d6", "surface-container-lowest": "#ffffff", "error": "#ba1a1a", "outline": "#727687", "inverse-primary": "#b3c5ff", "inverse-on-surface": "#eaf1ff", "tertiary": "#a33200", "secondary-container": "#dde3ec", "on-surface-variant": "#424656", "surface-variant": "#d3e4fe", "tertiary-fixed-dim": "#ffb59d", "on-surface": "#0b1c30", "error-container": "#ffdad6", "on-secondary-fixed-variant": "#41474f" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "stack-md": "16px", "margin-mobile": "16px", "container-max": "1280px", "stack-sm": "8px", "margin-desktop": "32px", "gutter": "24px", "stack-lg": "24px" }, "fontFamily": { "body-lg": ["Inter"], "body-sm": ["Inter"], "headline-md": ["Inter"], "label-sm": ["Inter"], "label-md": ["Inter"], "headline-lg-mobile": ["Inter"], "headline-lg": ["Inter"] }, "fontSize": { "body-lg": ["16px", { "lineHeight": "1.6", "fontWeight": "400" }], "body-sm": ["14px", { "lineHeight": "1.5", "fontWeight": "400" }], "headline-md": ["20px", { "lineHeight": "1.4", "fontWeight": "600" }], "label-sm": ["12px", { "lineHeight": "1", "letterSpacing": "0.01em", "fontWeight": "500" }], "label-md": ["14px", { "lineHeight": "1", "fontWeight": "600" }], "headline-lg-mobile": ["24px", { "lineHeight": "1.2", "fontWeight": "700" }], "headline-lg": ["32px", { "lineHeight": "1.2", "letterSpacing": "-0.02em", "fontWeight": "700" }] } } } };</script>
<meta content="web_dashboard" name="shell-type">
</head>
<body class="bg-background font-body-sm text-body-sm text-on-surface">

<!-- SIDEBAR KIRI -->
<aside class="fixed left-0 top-0 h-full w-72 bg-surface-container-lowest z-50 flex flex-col justify-between shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
  <div class="flex flex-col">
    <div class="h-20 px-6 flex items-center gap-3">
      <a href="dashboard.php" class="flex items-center gap-2">
        <div class="w-9 h-9 rounded-lg bg-primary-container flex items-center justify-center">
          <span class="material-symbols-outlined text-on-primary text-[22px]">apartment</span>
        </div>
        <div class="flex flex-col">
          <span class="font-headline-md text-headline-md text-on-surface tracking-tight leading-none">KosLink</span>
          <span class="font-label-sm text-label-sm text-on-surface-variant leading-none mt-1">Internal System</span>
        </div>
      </a>
      <span class="px-2 py-0.5 rounded-lg bg-surface-container-high text-primary font-label-sm text-label-sm">SUPER ADMIN</span>
    </div>

    <div class="px-4 py-2">
      <p class="px-3 mb-2 font-label-sm text-label-sm text-outline uppercase tracking-wider">Menu Utama</p>
      <nav class="flex flex-col gap-1">
        <a class="flex items-center justify-between px-3 py-2.5 rounded-lg bg-primary-container text-on-primary-container font-label-md text-body-sm shadow-sm transition-all" href="kelola_mitra.php">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">group</span>
            <span>Kelola Mitra</span>
          </div>
          <span class="px-2 py-0.5 rounded-full bg-surface-container-lowest text-primary font-label-sm text-label-sm font-semibold"><?= $jml_mitra ?></span>
        </a>
        <a class="flex items-center justify-between px-3 py-2.5 rounded-lg text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-all font-body-sm text-body-sm" href="dashboard.php">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">payments</span>
            <span>Laporan Penghasilan Mitra</span>
          </div>
        </a>
        <a class="flex items-center justify-between px-3 py-2.5 rounded-lg text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-all font-body-sm text-body-sm" href="validasi_kamar.php">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">hotel</span>
            <span>Validasi Kamar Baru</span>
          </div>
          <span class="px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-label-sm text-label-sm"><?= $jml_kamar_pending ?></span>
        </a>
      </nav>
    </div>
  </div>

  <div class="p-4 flex flex-col gap-3">
    <div class="p-4 rounded-xl bg-surface-container-low">
      <div class="flex items-center gap-2 mb-1.5">
        <span class="material-symbols-outlined text-primary text-[18px]">lock</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant font-medium">Escrow Pool Holding</span>
      </div>
      <div class="font-headline-md text-headline-md text-on-surface font-semibold"><?= rupiah($escrow_pool) ?></div>
      <div class="flex items-center gap-1.5 mt-2">
        <span class="w-2 h-2 rounded-full bg-primary"></span>
        <span class="font-label-sm text-label-sm text-on-surface-variant">Aman &amp; Terkunci</span>
      </div>
    </div>
    <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-error hover:bg-error-container hover:text-on-error-container transition-all font-body-sm text-body-sm" href="../auth/logout.php" onclick="return confirm('Keluar dari Portal Super Admin?')">
      <span class="material-symbols-outlined text-[20px]">logout</span>
      <span>Keluar Portal</span>
    </a>
  </div>
</aside>

<!-- KONTEN UTAMA -->
<div class="pl-72">
  <header class="fixed top-0 left-72 right-0 h-20 bg-surface/80 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-40 flex items-center justify-between px-8">
    <div class="w-96 flex items-center gap-2 bg-surface-container-lowest px-3 py-2 rounded-lg">
      <span class="material-symbols-outlined text-outline text-[20px]">search</span>
      <input class="w-full bg-transparent border-none outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline" placeholder="Cari mitra, properti kos, pemilik, tiket..." type="text">
    </div>
    <div class="flex items-center gap-6">
      <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest">
        <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
        <span class="font-label-sm text-label-sm text-on-surface-variant font-medium">Sistem Normal &bull; Escrow Aktif</span>
      </div>
      <a href="validasi_kamar.php" title="Antrean Transaksi & Validasi Kamar Pending" class="flex items-center gap-1.5 px-3 py-1.5 rounded-full <?= $total_notif > 0 ? 'bg-tertiary-fixed text-on-tertiary-fixed-variant' : 'bg-surface-variant text-primary' ?> text-xs font-semibold hover:bg-surface-container-high transition-all">
        <span class="material-symbols-outlined text-[16px]">notifications</span>
        <span><?= $total_notif ?> pending</span>
      </a>
      <div class="flex items-center gap-3 pl-4">
        <div class="flex flex-col text-right">
          <span class="font-label-md text-label-md text-on-surface"><?= e($nama_admin) ?></span>
          <span class="font-label-sm text-label-sm text-on-surface-variant">Head of Operations / Super Administrator</span>
        </div>
        <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center">
          <span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
        </div>
      </div>
    </div>
  </header>

  <main class="w-full pt-20 bg-background min-h-screen flex flex-col justify-between">
    <form method="POST" action="tambah_mitra.php" enctype="multipart/form-data" id="formTambahMitra" class="flex flex-col flex-1 justify-between">
      <input type="hidden" name="submit_mode" id="submitModeInput" value="verifikasi">
      <input type="hidden" name="tipe_kos" id="tipeKosInput" value="Putra">

      <div class="w-full max-w-5xl mx-auto px-6 py-7 flex flex-col gap-6 pb-28">
        <?php if ($error_tm !== ''): ?>
          <div class="p-4 rounded-xl bg-error-container text-on-error-container text-xs font-semibold flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            <span><?= e($error_tm) ?></span>
          </div>
        <?php endif; ?>

        <!-- Top Context & Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div class="flex flex-col gap-1.5">
            <nav class="flex items-center gap-2 text-on-surface-variant font-label-sm text-label-sm">
              <a href="dashboard.php" class="hover:text-primary transition-colors">Super Admin</a>
              <span class="material-symbols-outlined text-[14px] text-outline">chevron_right</span>
              <a href="kelola_mitra.php" class="hover:text-primary transition-colors">Kelola Mitra &amp; Properti</a>
              <span class="material-symbols-outlined text-[14px] text-outline">chevron_right</span>
              <span class="text-primary font-medium">Tambah Mitra Baru</span>
            </nav>
            <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight">Pendaftaran Mitra &amp; Properti Kos</h1>
            <p class="font-body-sm text-body-sm text-on-surface-variant">Daftarkan profil pemilik kos beserta legalitas &amp; informasi bangunan. Detail unit kamar, fasilitas, dan harga sewa akan diinput langsung oleh Mitra di akunnya.</p>
          </div>
          <a href="kelola_mitra.php" class="self-start md:self-center px-4 py-2.5 rounded-lg bg-surface-container-lowest shadow-sm hover:bg-surface-container-low text-on-surface font-label-md text-label-md transition-all flex items-center gap-1.5">
            <span class="material-symbols-outlined text-[18px]">close</span>
            <span>Batal</span>
          </a>
        </div>

        <!-- PROGRESS BAR & STEPPER TAB (4 TAHAP) -->
        <div class="flex flex-col gap-3">
          <div class="w-full h-2 rounded-full bg-surface-container overflow-hidden">
            <div id="wizardProgressBar" class="h-full bg-primary transition-all duration-300 rounded-full" style="width: 25%;"></div>
          </div>

          <div class="grid grid-cols-2 md:grid-cols-4 gap-2.5">
            <?php
            $steps_meta = [
              1 => ['Identitas Mitra', 'KYC & Kontak'],
              2 => ['Profil Properti', 'Lokasi & Tipe'],
              3 => ['Legalitas & Hak', 'SHM & PBB'],
              4 => ['Escrow Rekening', 'Rekening Payout'],
            ];
            foreach ($steps_meta as $num => [$st_title, $st_sub]):
            ?>
            <button type="button" onclick="goToStep(<?= $num ?>)" id="stepTab<?= $num ?>" class="text-left flex items-center gap-3 p-3 rounded-xl bg-surface-container-lowest shadow-sm border-2 <?= $num === 1 ? 'border-primary ring-2 ring-primary/15' : 'border-transparent opacity-75 hover:opacity-100' ?> transition-all">
              <div id="stepBadge<?= $num ?>" class="w-7 h-7 rounded-full <?= $num === 1 ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant' ?> flex items-center justify-center font-label-sm text-label-sm font-bold shrink-0">
                <?= $num ?>
              </div>
              <div class="flex flex-col min-w-0">
                <span class="font-label-sm text-label-sm text-on-surface truncate font-semibold"><?= e($st_title) ?></span>
                <span id="stepStatus<?= $num ?>" class="font-label-sm text-[11px] <?= $num === 1 ? 'text-primary font-medium' : 'text-outline' ?> truncate">
                  <?= $num === 1 ? 'Sedang diisi' : e($st_sub) ?>
                </span>
              </div>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ========================================================== -->
        <!-- HALAMAN / TAHAP 1: IDENTITAS & KONTAK PEMILIK KOS          -->
        <!-- ========================================================== -->
        <section id="stepPage1" class="step-page step-animate rounded-2xl bg-surface-container-lowest shadow-sm border-t-4 border-primary p-7 flex flex-col gap-6">
          <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-surface-container-high text-primary flex items-center justify-center">
                <span class="material-symbols-outlined text-[22px]">person_pin</span>
              </div>
              <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary font-semibold">Tahap 1 dari 4</span>
                <h2 class="font-headline-md text-headline-md text-on-surface">Identitas &amp; Kontak Pemilik Kos (Mitra Info)</h2>
              </div>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-surface-container text-primary font-label-sm text-label-sm">KYC Owner</span>
          </div>

          <div class="flex flex-col gap-2">
            <label class="font-label-md text-label-md text-on-surface">Tipe Kemitraan KosLink</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-2xl">
              <label class="cursor-pointer flex items-center justify-between p-3.5 rounded-xl bg-surface-container-low border border-primary/25 transition-all">
                <div class="flex items-center gap-3">
                  <input checked class="w-4 h-4 text-primary focus:ring-0 accent-primary" name="partnership_type" type="radio" value="Perorangan">
                  <div class="flex flex-col">
                    <span class="font-label-md text-label-md text-on-surface">Perorangan / Pemilik Langsung</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant">Individu pemegang hak kepemilikan</span>
                  </div>
                </div>
                <span class="material-symbols-outlined text-primary text-[20px]">check_circle</span>
              </label>
              <label class="cursor-pointer flex items-center justify-between p-3.5 rounded-xl bg-surface-container-lowest shadow-sm hover:bg-surface-container-low transition-all">
                <div class="flex items-center gap-3">
                  <input class="w-4 h-4 text-primary focus:ring-0 accent-primary" name="partnership_type" type="radio" value="Badan Usaha">
                  <div class="flex flex-col">
                    <span class="font-label-md text-label-md text-on-surface">Badan Usaha / Operator Pro</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant">PT, CV, atau Property Manager</span>
                  </div>
                </div>
              </label>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface flex items-center justify-between">
                <span>Nama Lengkap Pemilik (Sesuai e-KTP) <span class="text-error">*</span></span>
                <span class="font-label-sm text-label-sm text-outline">Wajib</span>
              </label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low focus-within:bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary/20 transition-all">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">badge</span>
                <input name="nama_mitra" data-req="1" data-label="Nama Lengkap Pemilik" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline" placeholder="Masukkan nama resmi pemilik" type="text">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface flex items-center justify-between">
                <span>Nomor Induk Kependudukan (NIK 16 Digit)</span>
                <span class="font-label-sm text-label-sm text-primary flex items-center gap-1 font-medium">
                  <span class="material-symbols-outlined text-[14px]">verified</span> Dukcapil Ready
                </span>
              </label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low focus-within:bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary/20 transition-all">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">fingerprint</span>
                <input name="nik" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface tracking-wider" maxlength="16" placeholder="16 digit NIK terdaftar" type="text">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nomor WhatsApp Aktif <span class="text-error">*</span></label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low focus-within:bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary/20 transition-all">
                <span class="font-label-md text-label-md text-on-surface-variant mr-2 pr-2">+62</span>
                <input name="no_hp" data-req="1" data-label="Nomor WhatsApp Aktif" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline" placeholder="812-xxxx-xxxx" type="tel">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Alamat Email Resmi (Login Mitra) <span class="text-error">*</span></label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low focus-within:bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary/20 transition-all">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">mail</span>
                <input name="email" data-req="1" data-label="Alamat Email Resmi" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline" placeholder="email.mitra@domain.com" type="email">
              </div>
            </div>

            <div class="flex flex-col gap-2 md:col-span-2">
              <label class="font-label-md text-label-md text-on-surface">Password Akun Portal Mitra <span class="text-outline font-normal">(Default: mitra123)</span></label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low focus-within:bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary/20 transition-all">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">key</span>
                <input name="password_mitra" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline" placeholder="Masukkan password login awal mitra..." type="text" value="mitra123">
              </div>
            </div>
          </div>

          <div class="flex flex-col gap-2">
            <label class="font-label-md text-label-md text-on-surface">Upload Foto Dokumen Asli e-KTP Pemilik</label>
            <div class="p-4 rounded-xl bg-surface-container-low flex flex-col sm:flex-row items-center justify-between gap-4">
              <div class="flex items-center gap-4 w-full sm:w-auto">
                <div class="w-16 h-12 rounded-lg bg-surface-container shrink-0 flex items-center justify-center text-primary">
                  <span class="material-symbols-outlined text-[26px]">id_card</span>
                </div>
                <div class="flex flex-col min-w-0">
                  <span id="ktpFileName" class="font-label-md text-label-md text-on-surface truncate">Belum ada file e-KTP dipilih</span>
                  <span class="font-label-sm text-label-sm text-on-surface-variant">Format JPG / PNG / PDF &bull; Maksimal 5 MB</span>
                </div>
              </div>
              <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <input type="file" name="file_ktp" id="fileKtpInput" accept=".jpg,.jpeg,.png,.pdf" class="hidden" onchange="document.getElementById('ktpFileName').textContent = this.files[0]?.name || 'Belum ada file e-KTP dipilih'">
                <button onclick="document.getElementById('fileKtpInput').click()" class="px-4 py-2 rounded-lg bg-surface-container-lowest text-on-surface shadow-sm font-label-sm text-label-sm hover:bg-surface-container transition-all" type="button">
                  Pilih File e-KTP
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- ========================================================== -->
        <!-- HALAMAN / TAHAP 2: PROFIL PROPERTI KOS                     -->
        <!-- ========================================================== -->
        <section id="stepPage2" class="step-page step-animate hidden rounded-2xl bg-surface-container-lowest shadow-sm border-t-4 border-primary p-7 flex flex-col gap-6">
          <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-surface-container-high text-primary flex items-center justify-center">
                <span class="material-symbols-outlined text-[22px]">home_work</span>
              </div>
              <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary font-semibold">Tahap 2 dari 4</span>
                <h2 class="font-headline-md text-headline-md text-on-surface">Profil Properti Kos</h2>
              </div>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-surface-container text-primary font-label-sm text-label-sm">Property Profile</span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nama Properti Kos <span class="text-error">*</span></label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low focus-within:bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary/20 transition-all">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">apartment</span>
                <input name="nama_kos" data-req="2" data-label="Nama Properti Kos" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface" placeholder="Contoh: Kos Griya Udayana Jimbaran" type="text">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Tipe Penghuni Kos <span class="text-error">*</span></label>
              <div class="grid grid-cols-4 gap-2" id="tipeKosGroup">
                <button onclick="selectTipeKos(this, 'Campur')" class="btn-tipe-kos py-2.5 rounded-lg bg-surface-container text-on-surface-variant font-label-sm text-label-sm hover:bg-surface-container-high transition-colors" type="button">Campur</button>
                <button onclick="selectTipeKos(this, 'Putra')" class="btn-tipe-kos py-2.5 rounded-lg bg-primary-container text-on-primary font-label-sm text-label-sm shadow-sm transition-colors" type="button">Putra</button>
                <button onclick="selectTipeKos(this, 'Putri')" class="btn-tipe-kos py-2.5 rounded-lg bg-surface-container text-on-surface-variant font-label-sm text-label-sm hover:bg-surface-container-high transition-colors" type="button">Putri</button>
                <button onclick="selectTipeKos(this, 'Pasutri')" class="btn-tipe-kos py-2.5 rounded-lg bg-surface-container text-on-surface-variant font-label-sm text-label-sm hover:bg-surface-container-high transition-colors" type="button">Pasutri</button>
              </div>
            </div>

            <div class="flex flex-col gap-2 md:col-span-2">
              <label class="font-label-md text-label-md text-on-surface">Klaster Area / Kampus Terdekat <span class="text-error">*</span></label>
              <div class="relative">
                <select name="lokasi" class="w-full px-3.5 py-3 rounded-xl bg-surface-container-low font-body-sm text-body-sm text-on-surface appearance-none outline-none cursor-pointer">
                  <option value="Jimbaran, Badung, Bali">Bali - Jimbaran / Sekitar Universitas Udayana (Kampus Bukit)</option>
                  <option value="Denpasar, Bali">Bali - Denpasar / Sekitar Kampus Sudirman</option>
                  <option value="Depok, Jawa Barat">Depok - Sekitar Kampus Universitas Indonesia (UI)</option>
                  <option value="Sleman, Yogyakarta">Sleman - Sekitar Universitas Gadjah Mada (UGM)</option>
                  <option value="Bandung, Jawa Barat">Bandung - Sekitar ITB &amp; Dipatiukur</option>
                  <option value="Surabaya, Jawa Timur">Surabaya - Sekitar ITS Sukolilo</option>
                </select>
                <span class="material-symbols-outlined text-outline text-[20px] absolute right-3 top-3.5 pointer-events-none">expand_more</span>
              </div>
            </div>

            <div class="flex flex-col gap-2 md:col-span-2">
              <label class="font-label-md text-label-md text-on-surface">Alamat Lengkap &amp; Patokan Lokasi <span class="text-error">*</span></label>
              <textarea name="alamat_lengkap" data-req="2" data-label="Alamat Lengkap Properti" class="w-full p-3.5 rounded-xl bg-surface-container-low focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary/20 outline-none font-body-sm text-body-sm text-on-surface resize-none" rows="3" placeholder="Masukkan jalan, nomor bangunan, kelurahan, dan patokan terdekat..."></textarea>
            </div>
          </div>

          <div class="p-4 rounded-xl bg-surface-container-low flex items-start gap-3">
            <span class="material-symbols-outlined text-primary text-[20px] shrink-0 mt-0.5">meeting_room</span>
            <div class="flex flex-col gap-0.5">
              <span class="font-label-md text-label-md text-on-surface">Pengaturan Tipe Kamar, Fasilitas &amp; Harga Sewa Dikelola Mitra</span>
              <p class="font-body-sm text-body-sm text-on-surface-variant">
                Super Admin hanya mendaftarkan identitas properti kos. Penambahan tipe kamar, fasilitas kos &amp; kamar, foto, dan tarif bulanan dilakukan langsung oleh Mitra melalui dashboard akun Mitra.
              </p>
            </div>
          </div>
        </section>

        <!-- ========================================================== -->
        <!-- HALAMAN / TAHAP 3: LEGALITAS & DOKUMEN KEPEMILIKAN         -->
        <!-- ========================================================== -->
        <section id="stepPage3" class="step-page step-animate hidden rounded-2xl bg-surface-container-lowest shadow-sm border-t-4 border-primary p-7 flex flex-col gap-6">
          <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-surface-container-high text-primary flex items-center justify-center">
                <span class="material-symbols-outlined text-[22px]">verified</span>
              </div>
              <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary font-semibold">Tahap 3 dari 4</span>
                <h2 class="font-headline-md text-headline-md text-on-surface">Legalitas &amp; Dokumen Kepemilikan Lahan</h2>
              </div>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-surface-container-high text-primary font-label-sm text-label-sm">Anti-Sengketa</span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Tipe Bukti Hak Kepemilikan Lahan</label>
              <div class="relative">
                <select name="jenis_hak" class="w-full px-3.5 py-3 rounded-xl bg-surface-container-low font-body-sm text-body-sm text-on-surface appearance-none outline-none cursor-pointer">
                  <option value="SHM">Sertifikat Hak Milik (SHM)</option>
                  <option value="SHGB">Sertifikat Hak Guna Bangunan (SHGB)</option>
                  <option value="AJB">Akta Jual Beli (AJB Notaris PPAT)</option>
                  <option value="Sewa Kelola">Surat Perjanjian Sewa Kelola Resmi (Minimal 5 Tahun)</option>
                </select>
                <span class="material-symbols-outlined text-outline text-[20px] absolute right-3 top-3.5 pointer-events-none">expand_more</span>
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nomor Sertifikat / Akta Resmi</label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">description</span>
                <input name="no_sertifikat" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface font-mono" placeholder="Contoh: SHM No. 04821/Jimbaran" type="text">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nomor Objek Pajak (NOP) PBB Terbaru</label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">receipt_long</span>
                <input name="nop_pbb" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface font-mono" placeholder="Contoh: 51.03.010.008.012-0482.0" type="text">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nomor Induk Berusaha (NIB) <span class="text-outline font-normal">(Opsional)</span></label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">corporate_fare</span>
                <input name="nib" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline" placeholder="Masukkan 13 digit NIB jika berbadan hukum" type="text">
              </div>
            </div>
          </div>

          <div class="flex flex-col gap-2">
            <label class="font-label-md text-label-md text-on-surface">Upload Berkas Fisik SHM &amp; Bukti Lunas PBB (PDF / Foto Asli)</label>
            <div onclick="document.getElementById('fileShmInput').click()" class="p-8 rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low/70 flex flex-col items-center justify-center text-center gap-2 cursor-pointer hover:bg-surface-container-low transition-colors">
              <input type="file" name="file_shm" id="fileShmInput" accept=".pdf,.jpg,.jpeg,.png" class="hidden" onchange="document.getElementById('shmFileLabel').textContent = this.files[0]?.name || 'Klik untuk menelusuri scan sertifikat SHM atau bukti PBB'">
              <div class="w-12 h-12 rounded-full bg-surface-container flex items-center justify-center text-primary mb-1">
                <span class="material-symbols-outlined text-[26px]">cloud_upload</span>
              </div>
              <p id="shmFileLabel" class="font-label-md text-label-md text-on-surface">Klik untuk menelusuri scan sertifikat SHM atau bukti PBB</p>
              <p class="font-body-sm text-body-sm text-on-surface-variant max-w-md">Mendukung format PDF, JPG, PNG resolusi tajam (Maksimal 15 MB per file).</p>
            </div>
          </div>
        </section>

        <!-- ========================================================== -->
        <!-- HALAMAN / TAHAP 4: REKENING BANK PAYOUT ESCROW             -->
        <!-- ========================================================== -->
        <section id="stepPage4" class="step-page step-animate hidden rounded-2xl bg-surface-container-lowest shadow-sm border-t-4 border-primary p-7 flex flex-col gap-6">
          <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-surface-container-high text-primary flex items-center justify-center">
                <span class="material-symbols-outlined text-[22px]">account_balance</span>
              </div>
              <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary font-semibold">Tahap 4 dari 4 (Final)</span>
                <h2 class="font-headline-md text-headline-md text-on-surface">Rekening Bank Payout Escrow &amp; Kebijakan Komisi</h2>
              </div>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-primary-container text-on-primary-container font-label-sm text-label-sm">Escrow Target</span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Bank Penerima Payout</label>
              <div class="relative">
                <select name="nama_bank" class="w-full px-3.5 py-3 rounded-xl bg-surface-container-low font-body-sm text-body-sm text-on-surface appearance-none outline-none cursor-pointer">
                  <option value="BCA">Bank Central Asia (BCA)</option>
                  <option value="Mandiri">Bank Mandiri</option>
                  <option value="BRI">Bank Rakyat Indonesia (BRI)</option>
                  <option value="BNI">Bank Negara Indonesia (BNI)</option>
                  <option value="CIMB Niaga">Bank CIMB Niaga</option>
                  <option value="Permata">Bank Permata</option>
                </select>
                <span class="material-symbols-outlined text-outline text-[20px] absolute right-3 top-3.5 pointer-events-none">expand_more</span>
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nomor Rekening Bank</label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">credit_card</span>
                <input name="no_rekening" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface font-mono" placeholder="Masukkan no rekening" type="text">
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <label class="font-label-md text-label-md text-on-surface">Nama Pemilik Rekening</label>
              <div class="flex items-center px-3.5 py-3 rounded-xl bg-surface-container-low">
                <span class="material-symbols-outlined text-outline text-[20px] mr-2">person</span>
                <input name="nama_pemilik" class="w-full bg-transparent outline-none font-body-sm text-body-sm text-on-surface uppercase" placeholder="SESUAI BUKU TABUNGAN" type="text">
              </div>
            </div>
          </div>

          <div class="p-4 rounded-xl bg-surface-container-low flex items-start gap-3">
            <span class="material-symbols-outlined text-primary text-[22px] shrink-0 mt-0.5">info</span>
            <div class="flex flex-col gap-1">
              <span class="font-label-md text-label-md text-on-surface">Kesesuaian Nama Rekening &amp; Rekber KosLink</span>
              <p class="font-body-sm text-body-sm text-on-surface-variant">
                Nama pada buku tabungan/rekening harus identik dengan nama e-KTP yang diverifikasi pada Tahap 1. Dana booking pencairan pertama akan ditransfer H+1 setelah penyewa check-in fisik.
              </p>
            </div>
          </div>

          <div class="flex flex-col gap-2">
            <label class="font-label-md text-label-md text-on-surface">Skema Bagi Hasil / Komisi Platform KosLink</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <label class="cursor-pointer p-4 rounded-xl bg-surface-container-low border border-primary/25 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <input checked class="w-4 h-4 text-primary accent-primary" name="commission_fee" type="radio" value="5.0%">
                  <div class="flex flex-col">
                    <span class="font-label-md text-label-md text-on-surface">5.0% Sewa Bulan Pertama (Standar KosLink Escrow)</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant">Bulan berikutnya bebas komisi (0%) tanpa potongan</span>
                  </div>
                </div>
                <span class="px-2 py-0.5 rounded bg-primary-container text-on-primary-container font-label-sm text-label-sm font-semibold">Rekomendasi</span>
              </label>
              <label class="cursor-pointer p-4 rounded-xl bg-surface-container-lowest shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <input class="w-4 h-4 text-primary accent-primary" name="commission_fee" type="radio" value="3.0%">
                  <div class="flex flex-col">
                    <span class="font-label-md text-label-md text-on-surface">3.0% Tiap Bulan (Full Property Management Pro)</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant">Termasuk pemeliharaan teknis &amp; staf kebersihan KosLink</span>
                  </div>
                </div>
              </label>
            </div>
          </div>
        </section>
      </div>

      <!-- STICKY BOTTOM WIZARD NAVIGATION BAR -->
      <div class="sticky bottom-0 left-0 right-0 bg-surface-container-lowest/95 backdrop-blur-md shadow-[0_-4px_20px_rgba(0,0,0,0.06)] px-8 py-4 z-30">
        <div class="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
          <div class="flex items-center gap-3 w-full sm:w-auto">
            <div id="bottomStepCircle" class="w-9 h-9 rounded-full bg-surface-container-high flex items-center justify-center text-primary font-label-md text-label-md font-bold">
              1/4
            </div>
            <div class="flex flex-col">
              <span id="bottomStepTitle" class="font-label-md text-label-md text-on-surface">Tahap 1: Identitas &amp; Kontak Pemilik</span>
              <span id="bottomStepDesc" class="font-label-sm text-label-sm text-on-surface-variant">Lengkapi profil pemilik untuk melanjutkan ke profil properti kos</span>
            </div>
          </div>

          <div class="flex items-center gap-2.5 w-full sm:w-auto justify-end">
            <button id="btnPrevStep" onclick="prevStep()" class="hidden px-4 py-2.5 rounded-xl bg-surface-container-low text-on-surface hover:bg-surface-container font-label-md text-label-md transition-all flex items-center gap-1.5" type="button">
              <span class="material-symbols-outlined text-[18px]">arrow_back</span>
              <span>Sebelumnya</span>
            </button>

            <button onclick="submitFormMitra('draf')" class="px-4 py-2.5 rounded-xl bg-surface-container text-primary hover:bg-surface-container-high font-label-md text-label-md transition-all" type="button">
              Simpan Draf
            </button>

            <button id="btnNextStep" onclick="nextStep()" class="px-6 py-2.5 rounded-xl bg-primary-container text-on-primary font-label-md text-label-md shadow-md hover:bg-primary transition-all flex items-center gap-1.5" type="button">
              <span>Selanjutnya</span>
              <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
            </button>

            <button id="btnFinalSubmit" onclick="submitFormMitra('verifikasi')" class="hidden px-6 py-2.5 rounded-xl bg-primary-container text-on-primary font-label-md text-label-md shadow-md hover:bg-primary transition-all flex items-center gap-2" type="button">
              <span class="material-symbols-outlined text-[18px]">verified</span>
              <span>Daftarkan &amp; Verifikasi Mitra</span>
            </button>
          </div>
        </div>
      </div>
    </form>
  </main>
</div>

<script>
let currentStep = 1;
const totalSteps = 4;

const stepInfo = {
  1: ['Tahap 1: Identitas & Kontak Pemilik', 'Lengkapi profil pemilik kos untuk melanjutkan ke informasi properti'],
  2: ['Tahap 2: Profil Properti Kos', 'Isi nama kos, tipe penghuni, klaster area, dan alamat properti'],
  3: ['Tahap 3: Legalitas & Kepemilikan Lahan', 'Lengkapi data sertifikat hak milik / PBB (opsional untuk draf)'],
  4: ['Tahap 4: Rekening Bank Payout Escrow', 'Tentukan rekening tujuan pencairan dana sewa & kirim pendaftaran']
};

function validateStep(stepNum) {
  const reqFields = document.querySelectorAll(`[data-req="${stepNum}"]`);
  for (const field of reqFields) {
    if (!field.value || field.value.trim() === '') {
      const label = field.getAttribute('data-label') || 'Kolom ini';
      alert(`Mohon isi "${label}" terlebih dahulu sebelum lanjut ke tahap berikutnya.`);
      field.focus();
      return false;
    }
    if (field.type === 'email' && !field.value.includes('@')) {
      alert('Format alamat email tidak valid.');
      field.focus();
      return false;
    }
  }
  return true;
}

function goToStep(targetStep) {
  if (targetStep < 1 || targetStep > totalSteps) return;
  if (targetStep > currentStep) {
    for (let s = currentStep; s < targetStep; s++) {
      if (!validateStep(s)) return;
    }
  }

  currentStep = targetStep;

  document.querySelectorAll('.step-page').forEach((el, idx) => {
    if (idx + 1 === currentStep) {
      el.classList.remove('hidden');
    } else {
      el.classList.add('hidden');
    }
  });

  document.getElementById('wizardProgressBar').style.width = ((currentStep / totalSteps) * 100) + '%';

  for (let i = 1; i <= totalSteps; i++) {
    const tab    = document.getElementById('stepTab' + i);
    const badge  = document.getElementById('stepBadge' + i);
    const status = document.getElementById('stepStatus' + i);

    if (i < currentStep) {
      tab.className = 'text-left flex items-center gap-3 p-3 rounded-xl bg-surface-container-lowest shadow-sm border-2 border-primary/30 transition-all';
      badge.className = 'w-7 h-7 rounded-full bg-primary text-on-primary flex items-center justify-center font-label-sm text-label-sm font-bold shrink-0';
      badge.innerHTML = '<span class="material-symbols-outlined text-[15px]">check</span>';
      status.className = 'font-label-sm text-[11px] text-primary font-medium truncate';
      status.textContent = 'Selesai';
    } else if (i === currentStep) {
      tab.className = 'text-left flex items-center gap-3 p-3 rounded-xl bg-surface-container-lowest shadow-sm border-2 border-primary ring-2 ring-primary/15 transition-all';
      badge.className = 'w-7 h-7 rounded-full bg-primary text-on-primary flex items-center justify-center font-label-sm text-label-sm font-bold shrink-0';
      badge.textContent = i;
      status.className = 'font-label-sm text-[11px] text-primary font-semibold truncate';
      status.textContent = 'Sedang diisi';
    } else {
      tab.className = 'text-left flex items-center gap-3 p-3 rounded-xl bg-surface-container-lowest shadow-sm border-2 border-transparent opacity-70 hover:opacity-100 transition-all';
      badge.className = 'w-7 h-7 rounded-full bg-surface-container text-on-surface-variant flex items-center justify-center font-label-sm text-label-sm font-bold shrink-0';
      badge.textContent = i;
      status.className = 'font-label-sm text-[11px] text-outline truncate';
      status.textContent = 'Menunggu';
    }
  }

  document.getElementById('bottomStepCircle').textContent = `${currentStep}/${totalSteps}`;
  document.getElementById('bottomStepTitle').textContent = stepInfo[currentStep][0];
  document.getElementById('bottomStepDesc').textContent = stepInfo[currentStep][1];

  const btnPrev   = document.getElementById('btnPrevStep');
  const btnNext   = document.getElementById('btnNextStep');
  const btnSubmit = document.getElementById('btnFinalSubmit');

  if (currentStep === 1) {
    btnPrev.classList.add('hidden');
  } else {
    btnPrev.classList.remove('hidden');
  }

  if (currentStep === totalSteps) {
    btnNext.classList.add('hidden');
    btnSubmit.classList.remove('hidden');
  } else {
    btnNext.classList.remove('hidden');
    btnSubmit.classList.add('hidden');
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function nextStep() {
  goToStep(currentStep + 1);
}

function prevStep() {
  goToStep(currentStep - 1);
}

function selectTipeKos(btn, val) {
  document.getElementById('tipeKosInput').value = val;
  document.querySelectorAll('.btn-tipe-kos').forEach(b => {
    b.className = 'btn-tipe-kos py-2.5 rounded-lg bg-surface-container text-on-surface-variant font-label-sm text-label-sm hover:bg-surface-container-high transition-colors';
  });
  btn.className = 'btn-tipe-kos py-2.5 rounded-lg bg-primary-container text-on-primary font-label-sm text-label-sm shadow-sm transition-colors';
}

function submitFormMitra(mode) {
  if (!validateStep(1)) { goToStep(1); return; }
  if (!validateStep(2)) { goToStep(2); return; }
  document.getElementById('submitModeInput').value = mode;
  document.getElementById('formTambahMitra').submit();
}
</script>
</body>
</html>