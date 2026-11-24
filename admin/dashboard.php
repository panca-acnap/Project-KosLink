<?php
require __DIR__ . '/../koneksi.php';

$role_sesi = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (empty($_SESSION['id_admin']) && !in_array($role_sesi, ['admin', 'mitra'], true)) {
    flash('error', 'Akses ditolak: sesi admin tidak terbaca (' . ($role_sesi ?: 'kosong') . ').');
    header("Location: ../auth/login.php");
    exit;
}

if (function_exists('expire_pending_transactions')) {
    @expire_pending_transactions();
}

$nama_admin = $_SESSION['nama'] ?? $_SESSION['nama_admin'] ?? 'Administrator';

// Helper cek kolom tabel
function getColsDash($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) $cols[] = $r['Field'];
    }
    return $cols;
}

$cols_kamar = getColsDash($conn, 'kamar');
$col_harga_k = in_array('harga_bulan', $cols_kamar) ? 'harga_bulan' : (in_array('harga_bulanan', $cols_kamar) ? 'harga_bulanan' : 'harga');
$col_stok_k  = in_array('sisa_kamar', $cols_kamar) ? 'sisa_kamar' : 'jumlah_unit';

// --- HANDLER AKSI RILIS PAYOUT ESCROW ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rilis_payout') {
    $id_mitra_payout = (int)($_POST['id_mitra'] ?? 0);
    if ($id_mitra_payout > 0) {
        // Tandai transaksi_escrow milik mitra tersebut sebagai cair/rilis jika tabelnya memiliki kolom status
        $cols_esc = getColsDash($conn, 'transaksi_escrow');
        if (in_array('id_mitra', $cols_esc) && in_array('status', $cols_esc)) {
            db_exec("UPDATE transaksi_escrow SET status = 'cair' WHERE id_mitra = ? AND status IN ('ditahan', 'pending')", 'i', [$id_mitra_payout]);
        }
        $_SESSION['flash_dash'] = "Payout escrow untuk Mitra #MTR-{$id_mitra_payout} berhasil dirilis ke rekening terdaftar.";
    }
    header("Location: dashboard.php");
    exit;
}

// --- FILTER BULAN & STATUS ---
$filter_bulan  = $_GET['bulan'] ?? date('Y-m');
$filter_status = $_GET['status'] ?? 'semua';

// --- QUERY DATA RIIL GLOBAL ---
$jml_mitra        = (int)(db_one("SELECT COUNT(*) AS c FROM mitra")['c'] ?? 0);
$jml_kamar_pending = in_array('status_validasi', $cols_kamar)
    ? (int)(db_one("SELECT COUNT(*) AS c FROM kamar WHERE status_validasi = 'pending'")['c'] ?? 0)
    : 0;

// Hitung Escrow Pool & Total GMV dari transaksi lunas
$stat_global = db_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status_pembayaran = 'lunas' THEN total_harga ELSE 0 END), 0) AS total_gmv,
        COUNT(CASE WHEN status_pembayaran = 'pending' THEN 1 END) AS trx_pending
    FROM transaksi
");
$total_gmv   = (float)($stat_global['total_gmv'] ?? 0);
$trx_pending = (int)($stat_global['trx_pending'] ?? 0);

// Cek saldo escrow riil dari tabel transaksi_escrow jika ada datanya
$cols_escrow = getColsDash($conn, 'transaksi_escrow');
$escrow_pool = $total_gmv;
if (!empty($cols_escrow)) {
    $col_nom_esc = in_array('jumlah_dana', $cols_escrow) ? 'jumlah_dana' : (in_array('nominal', $cols_escrow) ? 'nominal' : (in_array('total_dana', $cols_escrow) ? 'total_dana' : ''));
    if ($col_nom_esc !== '') {
        $q_esc = db_one("SELECT COALESCE(SUM(`$col_nom_esc`), 0) AS s FROM transaksi_escrow");
        if ((float)($q_esc['s'] ?? 0) > 0) {
            $escrow_pool = (float)$q_esc['s'];
        }
    }
}

// Rekap Penghasilan Per Mitra (100% dari database)
$rekap_mitra = db_all("
    SELECT 
        m.id_mitra,
        m.nama_mitra,
        m.email,
        m.no_hp,
        COALESCE(MAX(p.nama_kos), 'Belum Ada Properti') AS nama_kos,
        COALESCE(MAX(p.lokasi), '-') AS lokasi,
        COUNT(DISTINCT k.id_kamar) AS total_unit_kamar,
        COALESCE(SUM(CASE WHEN t.status_pembayaran = 'lunas' THEN t.total_harga ELSE 0 END), 0) AS total_sewa,
        COUNT(CASE WHEN t.status_pembayaran = 'lunas' THEN t.id_transaksi END) AS jml_trx_lunas
    FROM mitra m
    LEFT JOIN properti_kos p ON p.id_mitra = m.id_mitra
    LEFT JOIN kamar k ON k.id_properti = p.id_properti
    LEFT JOIN transaksi t ON t.id_mitra = m.id_mitra
    GROUP BY m.id_mitra, m.nama_mitra, m.email, m.no_hp
    ORDER BY total_sewa DESC, m.id_mitra DESC
");

$komisi_platform  = $total_gmv * 0.05;
$siap_transfer    = $total_gmv - $komisi_platform;
$mitra_siap_cair  = 0;
foreach ($rekap_mitra as $rm) {
    if ((float)$rm['total_sewa'] > 0) $mitra_siap_cair++;
}

// --- HANDLER EKSPOR CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rekap_penghasilan_mitra_' . date('Ymd') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID Mitra', 'Nama Mitra', 'Properti Kos', 'Jumlah Tipe Kamar', 'Total Sewa (Rp)', 'Komisi 5% (Rp)', 'Saldo Bersih Mitra (Rp)']);
    foreach ($rekap_mitra as $r) {
        $kotor  = (float)$r['total_sewa'];
        $komisi = $kotor * 0.05;
        $bersih = $kotor - $komisi;
        fputcsv($out, ['#MTR-' . $r['id_mitra'], $r['nama_mitra'], $r['nama_kos'], $r['total_unit_kamar'], $kotor, $komisi, $bersih]);
    }
    fclose($out);
    exit;
}

$flash_msg = $_SESSION['flash_dash'] ?? '';
unset($_SESSION['flash_dash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Laporan Penghasilan &amp; Payout Mitra - KosLink Super Admin</title>
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
        <a href="kelola_mitra.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">group</span>
            <span class="text-sm font-medium">Kelola Mitra</span>
          </div>
          <span class="px-2 py-0.5 rounded-full text-[11px] bg-surface-container text-on-surface-variant font-semibold"><?= $jml_mitra ?></span>
        </a>

        <a aria-current="page" href="dashboard.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-primary text-on-primary font-semibold shadow-sm transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">payments</span>
            <span class="text-sm">Laporan Penghasilan Mitra</span>
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

  <!-- ESCROW POOL & LOGOUT -->
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
  <!-- TOPBAR -->
  <header class="fixed top-0 left-72 right-0 h-[72px] bg-surface-container-lowest/90 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-40 flex items-center justify-between px-6">
    <div class="flex-1 max-w-xl">
      <div class="relative flex items-center">
        <span class="material-symbols-outlined absolute left-4 text-primary text-[20px] pointer-events-none">search</span>
        <input id="searchMitraInput" oninput="filterTabelMitra()" class="w-full h-11 pl-12 pr-4 bg-surface-container-low rounded-xl text-sm text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary/20 transition-all" placeholder="Cari mitra, properti kos, lokasi..." type="text">
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
          <span class="text-sm font-semibold text-on-surface leading-tight"><?= htmlspecialchars($nama_admin) ?></span>
          <span class="text-xs text-outline">Head of Operations / Super Administrator</span>
        </div>
        <div class="w-9 h-9 rounded-full bg-primary flex items-center justify-center shadow-sm">
          <span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
        </div>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main class="w-full pt-[72px] min-h-screen bg-background px-6 py-6">
    <?php if ($flash_msg !== ''): ?>
      <div class="mb-5 p-3.5 rounded-xl bg-surface-variant text-primary text-xs font-semibold flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">check_circle</span>
        <span><?= htmlspecialchars($flash_msg) ?></span>
      </div>
    <?php endif; ?>

    <!-- HEADER JUDUL & TOMBOL EKSPOR -->
    <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4 mb-6">
      <div class="space-y-1">
        <div class="flex items-center gap-2">
          <span class="px-2.5 py-1 rounded-full bg-surface-variant text-xs text-primary font-semibold tracking-wide uppercase">Rekap Payout &amp; Escrow</span>
          <div class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-surface-container-low text-xs text-on-surface-variant">
            <span class="w-2 h-2 rounded-full bg-primary animate-ping"></span>
            <span>Ledger Payout Terhubung</span>
          </div>
        </div>
        <h1 class="text-3xl font-bold text-on-surface tracking-tight">Laporan Penghasilan &amp; Payout Mitra</h1>
        <p class="text-sm text-on-surface-variant max-w-3xl">Pantau rekap total perputaran dana sewa, saldo escrow holding, dan riwayat transfer bagi hasil ke rekening mitra kos.</p>
      </div>

      <div class="flex flex-wrap items-center gap-2.5">
        <a href="dashboard.php" class="h-11 px-4 bg-surface-container-lowest hover:bg-surface-container-low text-on-surface font-semibold text-sm rounded-xl shadow-sm flex items-center gap-2 transition-all">
          <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>
          <span>Periode: <?= date('M Y') ?></span>
        </a>
        <a href="dashboard.php?export=csv" class="h-11 px-4 bg-surface-container-lowest hover:bg-surface-container-low text-on-surface font-semibold text-sm rounded-xl shadow-sm flex items-center gap-2 transition-all">
          <span class="material-symbols-outlined text-[18px] text-on-surface-variant">download</span>
          <span>Ekspor Rekap CSV</span>
        </a>
      </div>
    </div>

    <!-- 3 KARTU METRIK UTAMA (DATA RIIL) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div class="bg-surface-container-lowest p-5 rounded-xl shadow-sm flex flex-col justify-between hover:shadow-md transition-all">
        <div class="flex items-center justify-between mb-3">
          <span class="text-xs text-outline uppercase tracking-wider font-medium">Total GMV Sewa Mitra</span>
          <div class="w-8 h-8 rounded-lg bg-surface-container-low flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[20px]">payments</span>
          </div>
        </div>
        <div>
          <div class="text-2xl font-bold text-on-surface tracking-tight"><?= rupiah($total_gmv) ?></div>
          <div class="flex items-center gap-1.5 mt-2">
            <span class="flex items-center text-primary text-xs font-semibold bg-surface-variant px-1.5 py-0.5 rounded">
              <span class="material-symbols-outlined text-[14px]">trending_up</span> Live DB
            </span>
            <span class="text-xs text-outline">Total transaksi sewa lunas</span>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest p-5 rounded-xl shadow-sm flex flex-col justify-between hover:shadow-md transition-all">
        <div class="flex items-center justify-between mb-3">
          <span class="text-xs text-outline uppercase tracking-wider font-medium">Siap Ditransfer ke Mitra</span>
          <div class="w-8 h-8 rounded-lg bg-surface-variant flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[20px]">account_balance_wallet</span>
          </div>
        </div>
        <div>
          <div class="text-2xl font-bold text-primary tracking-tight"><?= rupiah($siap_transfer) ?></div>
          <div class="flex items-center gap-1.5 mt-2">
            <span class="inline-flex items-center gap-1 text-on-surface text-xs font-medium bg-surface-container-low px-2.5 py-0.5 rounded-full">
              <span class="w-1.5 h-1.5 rounded-full bg-primary"></span> <?= $mitra_siap_cair ?> Mitra Kos Memiliki Saldo
            </span>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest p-5 rounded-xl shadow-sm flex flex-col justify-between hover:shadow-md transition-all">
        <div class="flex items-center justify-between mb-3">
          <span class="text-xs text-outline uppercase tracking-wider font-medium">Pendapatan Komisi Platform</span>
          <div class="w-8 h-8 rounded-lg bg-surface-container-low flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[20px]">account_balance</span>
          </div>
        </div>
        <div>
          <div class="text-2xl font-bold text-on-surface tracking-tight"><?= rupiah($komisi_platform) ?></div>
          <div class="flex items-center gap-1.5 mt-2">
            <span class="flex items-center text-primary text-xs font-semibold bg-surface-variant px-1.5 py-0.5 rounded">
              <span class="material-symbols-outlined text-[14px]">check_circle</span> 5.0% Komisi
            </span>
            <span class="text-xs text-outline">Take rate bersih KosLink</span>
          </div>
        </div>
      </div>
    </div>

    <!-- TABEL REKAP PENGHASILAN MITRA (DATA RIIL) -->
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
        <div>
          <div class="flex items-center gap-2">
            <h2 class="text-lg font-semibold text-on-surface">Daftar Rekap Penghasilan Mitra Terkini</h2>
            <span class="px-2.5 py-0.5 rounded-full bg-surface-container-low text-primary text-xs font-semibold"><?= count($rekap_mitra) ?> Mitra Terdaftar</span>
          </div>
          <p class="text-xs text-outline mt-0.5">Rincian bagi hasil pembayaran sewa kamar dan otorisasi pencairan escrow ke rekening mitra kos.</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-xs text-outline">Filter Status:</span>
          <select id="filterPayoutStatus" onchange="filterTabelMitra()" class="h-9 px-3 bg-surface-container-low text-on-surface text-xs font-semibold rounded-lg outline-none cursor-pointer">
            <option value="semua">Semua Payout</option>
            <option value="siap">Siap Ditransfer (&gt; Rp 0)</option>
            <option value="kosong">Belum Ada Transaksi</option>
          </select>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left" id="tabelRekapMitra">
          <thead>
            <tr class="bg-surface-container-low text-outline text-xs">
              <th class="py-3 px-4 rounded-l-lg">Nama Mitra &amp; Kos</th>
              <th class="py-3 px-4">Total Sewa</th>
              <th class="py-3 px-4">Potongan Komisi (5%)</th>
              <th class="py-3 px-4">Saldo Bersih Mitra</th>
              <th class="py-3 px-4">Status Payout</th>
              <th class="py-3 px-4 rounded-r-lg text-right">Tindakan</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <?php if (empty($rekap_mitra)): ?>
              <tr>
                <td colspan="6" class="py-10 text-center text-outline text-xs">Belum ada data mitra terdaftar di database.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rekap_mitra as $rm):
                $kotor   = (float)$rm['total_sewa'];
                $komisi  = $kotor * 0.05;
                $bersih  = $kotor - $komisi;
                $ada_saldo = $bersih > 0;
              ?>
              <tr class="row-mitra hover:bg-surface-container-low/60 transition-colors"
                  data-search="<?= htmlspecialchars(strtolower($rm['nama_mitra'] . ' ' . $rm['nama_kos'] . ' ' . $rm['lokasi'])) ?>"
                  data-status="<?= $ada_saldo ? 'siap' : 'kosong' ?>">
                <td class="py-4 px-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-surface-container-low flex items-center justify-center text-primary font-semibold shrink-0">
                      <span class="material-symbols-outlined text-[20px]">business</span>
                    </div>
                    <div>
                      <span class="text-sm text-on-surface font-semibold block"><?= htmlspecialchars($rm['nama_mitra']) ?></span>
                      <span class="text-xs text-outline"><?= htmlspecialchars($rm['nama_kos']) ?> (<?= (int)$rm['total_unit_kamar'] ?> Tipe Kamar)</span>
                    </div>
                  </div>
                </td>
                <td class="py-4 px-4 font-medium text-on-surface"><?= rupiah($kotor) ?></td>
                <td class="py-4 px-4 text-outline font-medium"><?= rupiah($komisi) ?></td>
                <td class="py-4 px-4 font-semibold <?= $ada_saldo ? 'text-primary' : 'text-on-surface' ?>"><?= rupiah($bersih) ?></td>
                <td class="py-4 px-4">
                  <?php if ($ada_saldo): ?>
                    <span class="inline-flex items-center gap-1 text-primary bg-surface-variant px-2.5 py-0.5 rounded-full text-xs font-semibold">
                      <span class="material-symbols-outlined text-[14px]">pending</span> Siap Ditransfer
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1 text-on-surface-variant bg-surface-container-high px-2.5 py-0.5 rounded-full text-xs font-semibold">
                      <span class="material-symbols-outlined text-[14px]">check_circle</span> Saldo Rp 0
                    </span>
                  <?php endif; ?>
                </td>
                <td class="py-4 px-4 text-right">
                  <?php if ($ada_saldo): ?>
                    <form method="POST" action="dashboard.php" class="inline" onsubmit="return confirm('Rilis payout sebesar <?= rupiah($bersih) ?> ke <?= htmlspecialchars($rm['nama_mitra']) ?>?')">
                      <input type="hidden" name="action" value="rilis_payout">
                      <input type="hidden" name="id_mitra" value="<?= (int)$rm['id_mitra'] ?>">
                      <button type="submit" class="px-3 py-1.5 bg-primary text-on-primary hover:bg-primary/90 rounded-lg text-xs font-semibold shadow-sm transition-all">
                        Rilis Payout
                      </button>
                    </form>
                  <?php else: ?>
                    <button class="px-3 py-1.5 bg-surface-container-low text-outline rounded-lg text-xs cursor-not-allowed" disabled>Selesai</button>
                  <?php endif; ?>
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

<script>
function filterTabelMitra() {
  const q = (document.getElementById('searchMitraInput').value || '').toLowerCase().trim();
  const st = document.getElementById('filterPayoutStatus').value;
  document.querySelectorAll('.row-mitra').forEach(row => {
    const s = row.getAttribute('data-search') || '';
    const rSt = row.getAttribute('data-status') || '';
    const matchText = !q || s.includes(q);
    const matchStatus = st === 'semua' || rSt === st;
    row.style.display = (matchText && matchStatus) ? '' : 'none';
  });
}
</script>
</body>
</html>