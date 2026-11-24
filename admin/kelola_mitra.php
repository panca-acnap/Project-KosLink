<?php
require __DIR__ . '/../koneksi.php';

$role_sesi = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (empty($_SESSION['id_admin']) && !in_array($role_sesi, ['admin', 'mitra'], true)) {
    flash('error', 'Silakan masuk sebagai Super Admin terlebih dahulu.');
    header("Location: ../auth/login.php");
    exit;
}

$nama_admin = $_SESSION['nama'] ?? $_SESSION['nama_admin'] ?? 'Administrator';

function getColsKm($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) $cols[] = $r['Field'];
    }
    return $cols;
}

$cols_mitra = getColsKm($conn, 'mitra');
$cols_prop  = getColsKm($conn, 'properti_kos');
$cols_kamar = getColsKm($conn, 'kamar');
$cols_rek   = getColsKm($conn, 'rekening_mitra');

$has_status_mitra = in_array('status_mitra', $cols_mitra, true);
$has_status_prop  = in_array('status_verifikasi', $cols_prop, true);
$has_status_kamar = in_array('status_validasi', $cols_kamar, true);

// --- HANDLER HAPUS MITRA PERMANEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_mitra') {
    $id_mitra_hapus = (int)($_POST['id_mitra'] ?? 0);
    if ($id_mitra_hapus > 0) {
        $conn->begin_transaction();
        try {
            mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0");

            // 1. Hapus kamar & properti jika tabel properti_kos punya kolom id_mitra
            if (in_array('id_mitra', $cols_prop, true)) {
                $col_pk_prop = in_array('id_properti', $cols_prop, true) ? 'id_properti' : ($cols_prop[0] ?? 'id_properti');
                $q_prop = mysqli_query($conn, "SELECT `$col_pk_prop` AS idp FROM properti_kos WHERE id_mitra = $id_mitra_hapus");
                if ($q_prop && !empty($cols_kamar)) {
                    $col_fk_kamar = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : (in_array('id_kos', $cols_kamar, true) ? 'id_kos' : '');
                    while ($p = mysqli_fetch_assoc($q_prop)) {
                        $idp = (int)$p['idp'];
                        if ($col_fk_kamar !== '') {
                            db_exec("DELETE FROM kamar WHERE `$col_fk_kamar` = ?", 'i', [$idp]);
                        }
                    }
                }
                db_exec("DELETE FROM properti_kos WHERE id_mitra = ?", 'i', [$id_mitra_hapus]);
            }

            // 2. Hapus rekening mitra hanya jika tabel & kolomnya ada
            if (!empty($cols_rek) && in_array('id_mitra', $cols_rek, true)) {
                db_exec("DELETE FROM rekening_mitra WHERE id_mitra = ?", 'i', [$id_mitra_hapus]);
            }

            // 3. Hapus data utama dari tabel mitra
            $col_pk_mitra = in_array('id_mitra', $cols_mitra, true) ? 'id_mitra' : ($cols_mitra[0] ?? 'id_mitra');
            db_exec("DELETE FROM mitra WHERE `$col_pk_mitra` = ?", 'i', [$id_mitra_hapus]);

            mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
            $conn->commit();
            $_SESSION['flash_km'] = "Akun mitra beserta properti terkait berhasil dihapus permanen.";
        } catch (Throwable $e) {
            @mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
            $conn->rollback();
            $_SESSION['flash_km'] = "Gagal menghapus mitra: " . $e->getMessage();
        }
    }
    header("Location: kelola_mitra.php");
    exit;
}

// --- HANDLER VERIFIKASI / UBAH STATUS MITRA & PROPERTI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act      = $_POST['action'] ?? '';
    $id_mitra = (int)($_POST['id_mitra'] ?? 0);

    if ($id_mitra > 0 && in_array($act, ['approve_mitra', 'revisi_mitra', 'hold_mitra'], true)) {
        $status_m = $act === 'approve_mitra' ? 'aktif' : ($act === 'revisi_mitra' ? 'revisi' : 'hold');
        $status_p = $act === 'approve_mitra' ? 'disetujui' : 'revisi';

        if ($has_status_mitra) {
            db_exec("UPDATE mitra SET status_mitra = ? WHERE id_mitra = ?", 'si', [$status_m, $id_mitra]);
        }
        if ($has_status_prop) {
            db_exec("UPDATE properti_kos SET status_verifikasi = ? WHERE id_mitra = ?", 'si', [$status_p, $id_mitra]);
        }

        $label_msg = $act === 'approve_mitra'
            ? "Mitra #MTR-{$id_mitra} & propertinya berhasil diverifikasi (Aktif)."
            : ($act === 'revisi_mitra'
                ? "Permintaan revisi dokumen dikirim ke Mitra #MTR-{$id_mitra}."
                : "Akun Mitra #MTR-{$id_mitra} ditangguhkan sementara (Hold).");
        $_SESSION['flash_km'] = $label_msg;
    }
    header("Location: kelola_mitra.php");
    exit;
}

// --- QUERY DATA RIIL ---
$sel_status_m = $has_status_mitra ? "COALESCE(m.status_mitra, 'aktif')" : "'aktif'";
$sel_status_p = $has_status_prop  ? "COALESCE(MAX(p.status_verifikasi), 'disetujui')" : "'disetujui'";

$daftar_mitra = db_all("
    SELECT 
        m.id_mitra,
        m.nama_mitra,
        m.email,
        m.no_hp,
        $sel_status_m AS status_mitra,
        MAX(p.id_properti) AS id_properti,
        COALESCE(MAX(p.nama_kos), 'Belum Ada Properti') AS nama_kos,
        COALESCE(MAX(p.lokasi), '-') AS lokasi,
        COALESCE(MAX(p.tipe_kos), '-') AS tipe_kos,
        COALESCE(MAX(p.harga_mulai), 0) AS harga_mulai,
        $sel_status_p AS status_verifikasi,
        COUNT(DISTINCT k.id_kamar) AS jml_tipe_kamar,
        COALESCE(SUM(CASE WHEN t.status_pembayaran = 'lunas' THEN t.total_harga ELSE 0 END), 0) AS total_omzet
    FROM mitra m
    LEFT JOIN properti_kos p ON p.id_mitra = m.id_mitra
    LEFT JOIN kamar k ON k.id_properti = p.id_properti
    LEFT JOIN transaksi t ON t.id_mitra = m.id_mitra
    GROUP BY m.id_mitra, m.nama_mitra, m.email, m.no_hp
    ORDER BY m.id_mitra DESC
");

$jml_mitra         = count($daftar_mitra);
$jml_properti      = (int)(db_one("SELECT COUNT(*) AS c FROM properti_kos")['c'] ?? 0);
$jml_kamar_pending = $has_status_kamar
    ? (int)(db_one("SELECT COUNT(*) AS c FROM kamar WHERE status_validasi = 'pending'")['c'] ?? 0)
    : 0;

$mitra_aktif   = 0;
$mitra_pending = [];
foreach ($daftar_mitra as $m) {
    if ($m['status_mitra'] === 'aktif') {
        $mitra_aktif++;
    } else {
        $mitra_pending[] = $m;
    }
}

// Escrow pool & transaksi pending
$stat_trx = db_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status_pembayaran = 'lunas' THEN total_harga ELSE 0 END), 0) AS total_gmv,
        COUNT(CASE WHEN status_pembayaran = 'pending' THEN 1 END) AS trx_pending
    FROM transaksi
");
$escrow_pool = (float)($stat_trx['total_gmv'] ?? 0);
$trx_pending = (int)($stat_trx['trx_pending'] ?? 0);

// Data rekening mitra & tiket keluhan untuk panel audit bawah
$jml_rekening = !empty($cols_rek) ? (int)(db_one("SELECT COUNT(*) AS c FROM rekening_mitra")['c'] ?? 0) : 0;
$keluhan_open = 0;
$q_kel = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM tiket_keluhan WHERE status IN ('open', 'proses')");
if ($q_kel && ($rk = mysqli_fetch_assoc($q_kel))) {
    $keluhan_open = (int)$rk['c'];
}

$flash_km = $_SESSION['flash_km'] ?? '';
unset($_SESSION['flash_km']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Kelola Mitra &amp; Verifikasi Properti - KosLink Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script id="tailwind-config">
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "on-secondary-container": "#5e656d",
        "surface-dim": "#cbdbf5",
        "on-primary-fixed-variant": "#003fa4",
        "on-primary-container": "#f8f7ff",
        "background": "#f8f9ff",
        "secondary-fixed": "#dde3ec",
        "on-secondary-fixed": "#161c22",
        "surface-variant": "#d3e4fe",
        "secondary-fixed-dim": "#c1c7d0",
        "primary-fixed": "#dae1ff",
        "on-tertiary": "#ffffff",
        "on-surface-variant": "#424656",
        "secondary-container": "#dde3ec",
        "surface-container-highest": "#d3e4fe",
        "tertiary-fixed": "#ffdbd0",
        "on-error": "#ffffff",
        "on-primary-fixed": "#001849",
        "outline-variant": "#c2c6d8",
        "on-surface": "#0b1c30",
        "inverse-primary": "#b3c5ff",
        "on-tertiary-fixed-variant": "#832600",
        "error": "#ba1a1a",
        "surface-tint": "#0054d6",
        "error-container": "#ffdad6",
        "secondary": "#585f66",
        "on-tertiary-fixed": "#390c00",
        "surface": "#f8f9ff",
        "on-secondary-fixed-variant": "#41474f",
        "surface-container-low": "#eff4ff",
        "on-error-container": "#93000a",
        "on-secondary": "#ffffff",
        "tertiary-fixed-dim": "#ffb59d",
        "inverse-on-surface": "#eaf1ff",
        "primary-container": "#0066ff",
        "inverse-surface": "#213145",
        "primary": "#0050cb",
        "on-background": "#0b1c30",
        "primary-fixed-dim": "#b3c5ff",
        "surface-container": "#e5eeff",
        "on-tertiary-container": "#fff6f4",
        "outline": "#727687",
        "surface-container-lowest": "#ffffff",
        "surface-bright": "#f8f9ff",
        "tertiary-container": "#cc4204",
        "surface-container-high": "#dce9ff",
        "tertiary": "#a33200",
        "on-primary": "#ffffff"
      },
      fontFamily: { sans: ["Inter", "sans-serif"] }
    }
  }
};
</script>
<style>
  html, body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
  ::-webkit-scrollbar { width: 5px; height: 5px; }
  ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
</style>
</head>
<body class="bg-background text-sm text-on-surface antialiased">

<!-- SIDEBAR KIRI -->
<aside class="fixed left-0 top-0 h-screen w-72 bg-surface-container-lowest flex flex-col justify-between z-50 shadow-[0_1px_8px_rgba(0,0,0,0.04)] border-r border-outline-variant/40">
  <div>
    <div class="h-[72px] px-6 flex items-center justify-between">
      <a href="dashboard.php" class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center">
          <span class="material-symbols-outlined text-on-primary text-[20px]">home_work</span>
        </div>
        <span class="text-xl font-bold tracking-tight text-on-surface">Kos<span class="text-primary">Link</span></span>
      </a>
      <span class="px-2.5 py-1 bg-surface-variant text-primary rounded-full text-[11px] font-semibold">SUPER ADMIN</span>
    </div>

    <div class="px-3 py-4">
      <div class="px-3 mb-2 text-[11px] font-semibold text-outline uppercase tracking-wider">Operasional Inti</div>
      <nav class="space-y-1">
        <a aria-current="page" href="kelola_mitra.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-primary text-on-primary font-semibold shadow-sm transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">group</span>
            <span class="text-sm">Kelola Mitra</span>
          </div>
          <span class="px-2 py-0.5 rounded-full text-[11px] bg-white/20 text-on-primary font-semibold"><?= $jml_mitra ?></span>
        </a>

        <a href="dashboard.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">payments</span>
            <span class="text-sm font-medium">Laporan Penghasilan Mitra</span>
          </div>
        </a>

        <a href="validasi_kamar.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">verified</span>
            <span class="text-sm font-medium">Validasi Kamar Baru</span>
          </div>
          <?php if ($jml_kamar_pending > 0): ?>
            <span class="px-2 py-0.5 rounded-full text-[11px] bg-surface-variant text-primary font-semibold"><?= $jml_kamar_pending ?> Baru</span>
          <?php endif; ?>
        </a>
      </nav>
    </div>
  </div>

  <div class="p-4 flex flex-col gap-2.5">
    <div class="p-4 rounded-xl bg-surface-container-low flex flex-col gap-1.5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-1.5 text-primary">
          <span class="material-symbols-outlined text-[18px]">shield_lock</span>
          <span class="text-[11px] font-semibold uppercase tracking-wider">Escrow Pool</span>
        </div>
        <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
      </div>
      <div class="text-lg font-bold text-on-surface"><?= rupiah($escrow_pool) ?></div>
      <div class="text-xs text-outline">Aman &amp; Terkunci</div>
    </div>

    <a href="../auth/logout.php" onclick="return confirm('Keluar dari Portal Super Admin?')" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-error hover:bg-error-container/30 transition-all font-semibold text-sm">
      <span class="material-symbols-outlined text-[20px]">logout</span>
      <span>Keluar Portal</span>
    </a>
  </div>
</aside>

<!-- KONTEN KANAN -->
<div class="pl-72">
  <header class="fixed top-0 left-72 right-0 h-[72px] bg-surface-container-lowest/90 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-40 flex items-center justify-between px-6">
    <div class="flex-1 max-w-xl">
      <div class="relative flex items-center">
        <span class="material-symbols-outlined absolute left-4 text-primary text-[20px] pointer-events-none">search</span>
        <input id="searchDirektori" oninput="filterDirektori()" class="w-full h-11 pl-12 pr-4 bg-surface-container-low rounded-xl text-sm text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary/20 transition-all" placeholder="Cari nama mitra, email, properti kos, lokasi..." type="text">
      </div>
    </div>

    <div class="flex items-center gap-5">
      <div class="hidden lg:flex items-center gap-2 px-4 py-1.5 rounded-full bg-surface-container-low">
        <span class="w-2 h-2 rounded-full bg-primary"></span>
        <span class="text-xs text-on-surface-variant font-medium">Sistem Normal &bull; Escrow Aktif</span>
      </div>

      <a href="validasi_kamar.php" class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-surface-variant text-primary text-xs font-semibold hover:bg-surface-container-high transition-all">
        <span class="material-symbols-outlined text-[16px]">notifications</span>
        <span><?= $trx_pending + $jml_kamar_pending ?> pending</span>
      </a>

      <div class="flex items-center gap-3 pl-2">
        <div class="flex flex-col text-right">
          <span class="text-sm font-semibold text-on-surface leading-tight"><?= e($nama_admin) ?></span>
          <span class="text-xs text-outline">Head of Operations / Super Administrator</span>
        </div>
        <div class="w-9 h-9 rounded-full bg-primary flex items-center justify-center shadow-sm">
          <span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
        </div>
      </div>
    </div>
  </header>

  <main class="w-full pt-[72px] min-h-screen bg-background px-6 py-6 space-y-6">
    <?php if ($flash_km !== ''): ?>
      <div class="p-3.5 rounded-xl bg-surface-variant text-primary text-xs font-semibold flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">check_circle</span>
        <span><?= e($flash_km) ?></span>
      </div>
    <?php endif; ?>

    <!-- HEADER JUDUL & TOMBOL TAMBAH MITRA -->
    <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
      <div class="space-y-1">
        <div class="flex items-center gap-2">
          <span class="px-2.5 py-1 rounded-full bg-surface-variant text-xs text-primary font-semibold tracking-wide uppercase">Manajemen Kemitraan &amp; KYC</span>
          <span class="px-2.5 py-0.5 rounded-full bg-surface-container-low text-xs text-on-surface-variant">1 Mitra = 1 Properti Kos</span>
        </div>
        <h1 class="text-3xl font-bold text-on-surface tracking-tight">Kelola Mitra &amp; Verifikasi Properti</h1>
        <p class="text-sm text-on-surface-variant max-w-3xl">Verifikasi legalitas pemilik kos, kelola status aktif kemitraan, dan pantau portofolio unit kamar terdaftar di KosLink.</p>
      </div>

      <div class="flex items-center gap-2.5">
        <a href="tambah_mitra.php" class="h-11 px-4 bg-primary hover:bg-primary-container text-on-primary font-semibold text-sm rounded-xl shadow-sm flex items-center gap-2 transition-all">
          <span class="material-symbols-outlined text-[18px]">person_add</span>
          <span>Daftarkan Mitra Baru</span>
        </a>
      </div>
    </div>

    <!-- 3 KARTU METRIK RINGKAS -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-surface-container-lowest p-5 rounded-xl shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-outline uppercase tracking-wider font-medium">Total Mitra Terdaftar</span>
          <div class="w-8 h-8 rounded-lg bg-surface-container-low flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[20px]">groups</span>
          </div>
        </div>
        <div class="text-2xl font-bold text-on-surface"><?= $jml_mitra ?> <span class="text-xs font-normal text-outline">Mitra</span></div>
        <div class="text-xs text-primary font-medium mt-1"><?= $mitra_aktif ?> mitra aktif terverifikasi</div>
      </div>

      <div class="bg-surface-container-lowest p-5 rounded-xl shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-outline uppercase tracking-wider font-medium">Total Properti Kos</span>
          <div class="w-8 h-8 rounded-lg bg-surface-variant flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[20px]">apartment</span>
          </div>
        </div>
        <div class="text-2xl font-bold text-primary"><?= $jml_properti ?> <span class="text-xs font-normal text-outline">Properti</span></div>
        <div class="text-xs text-outline mt-1">Terdaftar pada katalog pencarian</div>
      </div>

      <div class="bg-surface-container-lowest p-5 rounded-xl shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-outline uppercase tracking-wider font-medium">Antrean Verifikasi / Revisi</span>
          <div class="w-8 h-8 rounded-lg bg-tertiary-fixed/50 flex items-center justify-center text-tertiary">
            <span class="material-symbols-outlined text-[20px]">pending_actions</span>
          </div>
        </div>
        <div class="text-2xl font-bold text-on-surface"><?= count($mitra_pending) ?> <span class="text-xs font-normal text-outline">Akun</span></div>
        <div class="text-xs text-tertiary font-medium mt-1">Memerlukan tinjauan Super Admin</div>
      </div>
    </div>

    <!-- KARTU ANTREAN VERIFIKASI MITRA BARU (JIKA ADA) -->
    <?php if (!empty($mitra_pending)): ?>
    <div class="bg-surface-container-low p-5 rounded-xl border border-primary/15 space-y-3">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-primary text-[20px]">verified_user</span>
          <h2 class="text-base font-bold text-on-surface">Antrean Verifikasi Mitra &amp; Properti</h2>
        </div>
        <span class="px-2.5 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container text-[11px] font-semibold"><?= count($mitra_pending) ?> Menunggu</span>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <?php foreach ($mitra_pending as $mp): ?>
        <div class="bg-surface-container-lowest p-4 rounded-xl shadow-xs flex flex-col justify-between gap-3">
          <div class="flex items-start justify-between gap-2">
            <div>
              <span class="text-[11px] font-mono text-primary font-semibold">#MTR-<?= (int)$mp['id_mitra'] ?></span>
              <h3 class="text-sm font-bold text-on-surface"><?= e($mp['nama_mitra']) ?></h3>
              <p class="text-xs text-on-surface-variant"><?= e($mp['nama_kos']) ?> &bull; <?= e($mp['lokasi']) ?></p>
            </div>
            <span class="px-2.5 py-0.5 rounded-full bg-tertiary-fixed text-on-tertiary-fixed-variant text-[10px] font-semibold uppercase"><?= e($mp['status_mitra']) ?></span>
          </div>
          <div class="flex items-center justify-end gap-2 pt-2 border-t border-outline-variant/30">
            <form method="POST" action="kelola_mitra.php" class="inline">
              <input type="hidden" name="id_mitra" value="<?= (int)$mp['id_mitra'] ?>">
              <input type="hidden" name="action" value="revisi_mitra">
              <button type="submit" class="px-3 py-1.5 rounded-lg bg-surface-container-low text-on-surface-variant hover:bg-surface-container text-xs font-semibold">Minta Revisi</button>
            </form>
            <form method="POST" action="kelola_mitra.php" class="inline">
              <input type="hidden" name="id_mitra" value="<?= (int)$mp['id_mitra'] ?>">
              <input type="hidden" name="action" value="approve_mitra">
              <button type="submit" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container text-xs font-semibold shadow-xs">Setujui &amp; Aktifkan</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- TABEL DIREKTORI MITRA LENGKAP -->
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-lg font-semibold text-on-surface">Direktori Mitra &amp; Properti Terdaftar</h2>
          <p class="text-xs text-outline mt-0.5">Data langsung dari tabel <code>mitra</code>, <code>properti_kos</code>, dan <code>kamar</code>.</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-xs text-outline">Status:</span>
          <select id="filterStatusMitra" onchange="filterDirektori()" class="h-9 px-3 bg-surface-container-low text-on-surface text-xs font-semibold rounded-lg outline-none cursor-pointer">
            <option value="semua">Semua Status</option>
            <option value="aktif">Aktif</option>
            <option value="pending">Pending</option>
            <option value="revisi">Revisi</option>
            <option value="hold">Hold</option>
          </select>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="bg-surface-container-low text-outline text-xs">
              <th class="py-3 px-4 rounded-l-lg">Mitra &amp; Kontak</th>
              <th class="py-3 px-4">Properti Kos</th>
              <th class="py-3 px-4">Tipe &amp; Kamar</th>
              <th class="py-3 px-4">Total Omzet</th>
              <th class="py-3 px-4">Status</th>
              <th class="py-3 px-4 rounded-r-lg text-right">Aksi Admin</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <?php if (empty($daftar_mitra)): ?>
              <tr>
                <td colspan="6" class="py-10 text-center text-outline text-xs">Belum ada mitra yang terdaftar. Klik "Daftarkan Mitra Baru" untuk menambahkan.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($daftar_mitra as $m):
                $st = strtolower((string)$m['status_mitra']);
              ?>
              <tr class="row-direktori hover:bg-surface-container-low/60 transition-colors border-b border-outline-variant/20"
                  data-search="<?= e(strtolower($m['nama_mitra'] . ' ' . $m['email'] . ' ' . $m['nama_kos'] . ' ' . $m['lokasi'])) ?>"
                  data-status="<?= e($st) ?>">
                <td class="py-3.5 px-4">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-surface-container-low text-primary flex items-center justify-center font-bold text-xs shrink-0">
                      <?= strtoupper(substr($m['nama_mitra'], 0, 2)) ?>
                    </div>
                    <div>
                      <span class="font-semibold text-on-surface block"><?= e($m['nama_mitra']) ?></span>
                      <span class="text-xs text-outline"><?= e($m['email']) ?> &bull; <?= e($m['no_hp'] ?: '-') ?></span>
                    </div>
                  </div>
                </td>
                <td class="py-3.5 px-4">
                  <span class="font-semibold text-on-surface block"><?= e($m['nama_kos']) ?></span>
                  <span class="text-xs text-outline"><?= e($m['lokasi']) ?></span>
                </td>
                <td class="py-3.5 px-4">
                  <span class="px-2 py-0.5 rounded bg-surface-container-low text-on-surface text-xs font-medium uppercase"><?= e($m['tipe_kos']) ?></span>
                  <span class="text-xs text-outline block mt-1"><?= (int)$m['jml_tipe_kamar'] ?> tipe kamar &bull; Mulai <?= rupiah($m['harga_mulai']) ?></span>
                </td>
                <td class="py-3.5 px-4 font-semibold text-on-surface"><?= rupiah($m['total_omzet']) ?></td>
                <td class="py-3.5 px-4">
                  <?php if ($st === 'aktif'): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-surface-variant text-primary text-xs font-semibold">
                      <span class="w-1.5 h-1.5 rounded-full bg-primary"></span> Aktif
                    </span>
                  <?php elseif ($st === 'hold'): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container text-xs font-semibold">
                      Hold
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-tertiary-fixed text-on-tertiary-fixed-variant text-xs font-semibold uppercase">
                      <?= e($st) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-4 text-right">
                  <div class="inline-flex items-center gap-1.5">
                    <?php if ($st !== 'aktif'): ?>
                      <form method="POST" action="kelola_mitra.php" class="inline">
                        <input type="hidden" name="id_mitra" value="<?= (int)$m['id_mitra'] ?>">
                        <input type="hidden" name="action" value="approve_mitra">
                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-primary text-on-primary text-xs font-semibold hover:bg-primary-container">Aktifkan</button>
                      </form>
                    <?php else: ?>
                      <form method="POST" action="kelola_mitra.php" class="inline" onsubmit="return confirm('Tangguhkan sementara akun mitra ini?')">
                        <input type="hidden" name="id_mitra" value="<?= (int)$m['id_mitra'] ?>">
                        <input type="hidden" name="action" value="hold_mitra">
                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-surface-container-low text-error hover:bg-error-container/40 text-xs font-semibold">Hold</button>
                      </form>
                    <?php endif; ?>

                    <form method="POST" action="kelola_mitra.php" class="inline" onsubmit="return confirm('Yakin ingin menghapus permanen mitra ini beserta seluruh properti dan kamarnya? Tindakan ini tidak dapat dibatalkan.');">
                      <input type="hidden" name="aksi" value="hapus_mitra">
                      <input type="hidden" name="id_mitra" value="<?= (int)$m['id_mitra'] ?>">
                      <button type="submit" title="Hapus Mitra Permanen" class="px-2.5 py-1.5 rounded-lg bg-error-container text-on-error-container hover:bg-error hover:text-on-error text-xs font-semibold transition-all inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-[15px]">delete</span>
                        <span>Hapus</span>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- PANEL AUDIT KEPATUHAN & REKENING ESCROW (DATA RIIL) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-surface-container-lowest p-4 rounded-xl shadow-sm flex items-center gap-3.5">
        <div class="w-10 h-10 rounded-xl bg-surface-container-low text-primary flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-[20px]">account_balance</span>
        </div>
        <div>
          <span class="text-xs text-outline block">Rekening Payout Terdaftar</span>
          <span class="text-base font-bold text-on-surface"><?= $jml_rekening ?> Rekening Bank</span>
        </div>
      </div>

      <div class="bg-surface-container-lowest p-4 rounded-xl shadow-sm flex items-center gap-3.5">
        <div class="w-10 h-10 rounded-xl bg-surface-container-low text-primary flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-[20px]">support_agent</span>
        </div>
        <div>
          <span class="text-xs text-outline block">Tiket Keluhan Aktif</span>
          <span class="text-base font-bold text-on-surface"><?= $keluhan_open ?> Tiket Terbuka</span>
        </div>
      </div>

      <div class="bg-surface-container-lowest p-4 rounded-xl shadow-sm flex items-center gap-3.5">
        <div class="w-10 h-10 rounded-xl bg-surface-variant text-primary flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-[20px]">policy</span>
        </div>
        <div>
          <span class="text-xs text-outline block">Aturan Kemitraan Sistem</span>
          <span class="text-base font-bold text-primary">1 Mitra = 1 Properti Kos</span>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
function filterDirektori() {
  const q = (document.getElementById('searchDirektori').value || '').toLowerCase().trim();
  const st = document.getElementById('filterStatusMitra').value;
  document.querySelectorAll('.row-direktori').forEach(row => {
    const s = row.getAttribute('data-search') || '';
    const rSt = row.getAttribute('data-status') || '';
    const okText = !q || s.includes(q);
    const okSt = st === 'semua' || rSt === st;
    row.style.display = (okText && okSt) ? '' : 'none';
  });
}
</script>
</body>
</html>