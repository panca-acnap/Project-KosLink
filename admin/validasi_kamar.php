<?php
require __DIR__ . '/../koneksi.php';

$role_sesi = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (empty($_SESSION['id_admin']) && !in_array($role_sesi, ['admin', 'mitra'], true)) {
    flash('error', 'Silakan masuk sebagai Super Admin terlebih dahulu.');
    header("Location: ../auth/login.php");
    exit;
}

$nama_admin = $_SESSION['nama'] ?? $_SESSION['nama_admin'] ?? 'Administrator';

function getColsVk($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) $cols[] = $r['Field'];
    }
    return $cols;
}

$cols_kamar = getColsVk($conn, 'kamar');
$cols_prop  = getColsVk($conn, 'properti_kos');
$cols_foto  = getColsVk($conn, 'foto_kamar');

if (!in_array('status_validasi', $cols_kamar, true)) {
    @mysqli_query($conn, "ALTER TABLE `kamar` ADD COLUMN `status_validasi` VARCHAR(30) DEFAULT 'pending'");
    $cols_kamar[] = 'status_validasi';
}
if (!in_array('catatan_validasi', $cols_kamar, true)) {
    @mysqli_query($conn, "ALTER TABLE `kamar` ADD COLUMN `catatan_validasi` TEXT NULL");
    $cols_kamar[] = 'catatan_validasi';
}

$col_harga_k   = in_array('harga_bulan', $cols_kamar) ? 'harga_bulan' : (in_array('harga_bulanan', $cols_kamar) ? 'harga_bulanan' : 'harga');
$col_stok_k    = in_array('sisa_kamar', $cols_kamar) ? 'sisa_kamar' : 'jumlah_unit';
$col_lokasi_p  = in_array('lokasi', $cols_prop) ? 'lokasi' : (in_array('alamat', $cols_prop) ? 'alamat' : 'nama_kos');
$col_tipe_p    = in_array('tipe_kos', $cols_prop) ? 'tipe_kos' : (in_array('jenis_kos', $cols_prop) ? 'jenis_kos' : "'Campur'");
$sel_tipe_p    = in_array($col_tipe_p, ['tipe_kos', 'jenis_kos']) ? "COALESCE(p.`$col_tipe_p`, 'Campur')" : "'Campur'";

// --- HANDLER VALIDASI KAMAR BARU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act      = $_POST['action'] ?? '';
    $id_kamar = (int)($_POST['id_kamar'] ?? 0);
    $catatan  = trim($_POST['catatan_validasi'] ?? '');

    if ($id_kamar > 0 && in_array($act, ['setujui_kamar', 'revisi_kamar'], true)) {
        $st_baru = $act === 'setujui_kamar' ? 'disetujui' : 'revisi';
        db_exec("UPDATE kamar SET status_validasi = ?, catatan_validasi = ? WHERE id_kamar = ?", 'ssi', [$st_baru, $catatan, $id_kamar]);

        $_SESSION['flash_vk'] = $act === 'setujui_kamar'
            ? "Unit Kamar #KMR-{$id_kamar} disetujui, tayang aktif di marketplace KosLink, dan dihapus dari antrean verifikasi."
            : "Catatan revisi telah dikirim ke mitra untuk Unit Kamar #KMR-{$id_kamar}.";
    }
    header("Location: validasi_kamar.php");
    exit;
}

// --- QUERY DATA KAMAR & FOTO ASLI (GUNAKAN LEFT JOIN AGAR TIDAK ADA KAMAR YANG HILANG) ---
$daftar_kamar = db_all("
    SELECT 
        k.id_kamar,
        k.nama_kamar,
        k.`$col_harga_k` AS harga_sewa,
        k.`$col_stok_k` AS stok_kamar,
        COALESCE(k.fasilitas, '-') AS fasilitas,
        LOWER(TRIM(COALESCE(k.status_validasi, 'pending'))) AS status_validasi,
        COALESCE(k.catatan_validasi, '') AS catatan_validasi,
        COALESCE(p.id_properti, 0) AS id_properti,
        COALESCE(p.nama_kos, 'Properti Mitra') AS nama_kos,
        COALESCE(p.`$col_lokasi_p`, 'Lokasi Kos') AS lokasi,
        $sel_tipe_p AS tipe_kos,
        COALESCE(m.id_mitra, 0) AS id_mitra,
        COALESCE(m.nama_mitra, 'Mitra KosLink') AS nama_mitra,
        COALESCE(m.no_hp, '-') AS no_hp
    FROM kamar k
    LEFT JOIN properti_kos p ON p.id_properti = k.id_properti
    LEFT JOIN mitra m ON m.id_mitra = p.id_mitra
    ORDER BY 
        CASE 
            WHEN LOWER(TRIM(COALESCE(k.status_validasi, 'pending'))) = 'pending' THEN 0 
            WHEN LOWER(TRIM(COALESCE(k.status_validasi, 'pending'))) = 'revisi' THEN 1 
            ELSE 2 
        END,
        k.id_kamar DESC
");

// Ambil semua foto dari tabel foto_kamar jika tersedia
$foto_by_kamar = [];
if (!empty($cols_foto)) {
    $col_path_f = in_array('path_foto', $cols_foto) ? 'path_foto' : (in_array('url_foto', $cols_foto) ? 'url_foto' : (in_array('nama_file', $cols_foto) ? 'nama_file' : 'foto'));
    $col_id_ref = in_array('id_kamar', $cols_foto) ? 'id_kamar' : (in_array('id_properti', $cols_foto) ? 'id_properti' : '');
    if ($col_id_ref !== '') {
        $all_fotos = db_all("SELECT `$col_id_ref` AS ref_id, `$col_path_f` AS file_foto FROM foto_kamar");
        foreach ($all_fotos as $af) {
            if (!empty($af['file_foto'])) {
                $foto_by_kamar[(int)$af['ref_id']][] = $af['file_foto'];
            }
        }
    }
}

$jml_mitra         = (int)(db_one("SELECT COUNT(*) AS c FROM mitra")['c'] ?? 0);
$jml_kamar_pending = 0;
$jml_kamar_revisi  = 0;
$jml_kamar_aktif   = 0;
foreach ($daftar_kamar as $dk) {
    $sv = $dk['status_validasi'];
    if ($sv === 'pending') $jml_kamar_pending++;
    elseif ($sv === 'revisi') $jml_kamar_revisi++;
    elseif ($sv === 'disetujui') $jml_kamar_aktif++;
}
$jml_antrean = $jml_kamar_pending + $jml_kamar_revisi;

$stat_trx = db_one("
    SELECT 
        COALESCE(SUM(CASE WHEN status_pembayaran = 'lunas' THEN total_harga ELSE 0 END), 0) AS total_gmv,
        COUNT(CASE WHEN status_pembayaran = 'pending' THEN 1 END) AS trx_pending
    FROM transaksi
");
$escrow_pool = (float)($stat_trx['total_gmv'] ?? 0);
$trx_pending = (int)($stat_trx['trx_pending'] ?? 0);

$flash_vk = $_SESSION['flash_vk'] ?? '';
unset($_SESSION['flash_vk']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Validasi &amp; Survei Kamar Baru - KosLink Super Admin</title>
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

        <a href="dashboard.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">payments</span>
            <span class="text-sm font-medium">Laporan Penghasilan Mitra</span>
          </div>
        </a>

        <a aria-current="page" href="validasi_kamar.php" class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-primary text-on-primary font-semibold shadow-sm transition-all">
          <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[20px]">verified</span>
            <span class="text-sm">Validasi Kamar Baru</span>
          </div>
          <span class="px-2 py-0.5 rounded-full text-[11px] bg-white/20 text-on-primary font-semibold"><?= $jml_kamar_pending ?> Baru</span>
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
        <input id="searchKamarInput" oninput="filterKamarCards()" class="w-full h-11 pl-12 pr-4 bg-surface-container-low rounded-xl text-sm text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary/20 transition-all" placeholder="Cari tipe kamar, nama kos, pemilik..." type="text">
      </div>
    </div>

    <div class="flex items-center gap-5">
      <div class="hidden lg:flex items-center gap-2 px-4 py-1.5 rounded-full bg-surface-container-low">
        <span class="w-2 h-2 rounded-full bg-primary"></span>
        <span class="text-xs text-on-surface-variant font-medium">Sistem Normal &bull; Escrow Aktif</span>
      </div>

      <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-surface-variant text-primary text-xs font-semibold">
        <span class="material-symbols-outlined text-[16px]">notifications</span>
        <span><?= $trx_pending + $jml_kamar_pending ?> pending</span>
      </div>

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
    <?php if ($flash_vk !== ''): ?>
      <div class="p-3.5 rounded-xl bg-surface-variant text-primary text-xs font-semibold flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">check_circle</span>
        <span><?= e($flash_vk) ?></span>
      </div>
    <?php endif; ?>

    <!-- HEADER JUDUL & FILTER STATUS -->
    <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
      <div class="space-y-1">
        <div class="flex items-center gap-2">
          <span class="px-2.5 py-1 rounded-full bg-surface-variant text-xs text-primary font-semibold tracking-wide uppercase">Quality Control &amp; Survei</span>
          <span class="px-2.5 py-0.5 rounded-full bg-surface-container-low text-xs text-on-surface-variant"><?= $jml_kamar_aktif ?> Kamar Tayang Aktif</span>
        </div>
        <h1 class="text-3xl font-bold text-on-surface tracking-tight">Validasi &amp; Survei Kamar Baru</h1>
        <p class="text-sm text-on-surface-variant max-w-3xl">Periksa kelengkapan foto unit, kesesuaian fasilitas, dan harga sewa sebelum kamar diterbitkan ke pencari kos.</p>
      </div>

      <div class="flex items-center gap-2">
        <span class="text-xs text-outline">Tampilkan:</span>
        <select id="filterStatusKamar" onchange="filterKamarCards()" class="h-10 px-3.5 bg-surface-container-lowest text-on-surface text-xs font-semibold rounded-xl shadow-xs outline-none cursor-pointer">
          <option value="antrean" selected>Antrean Verifikasi (<?= $jml_antrean ?>)</option>
          <option value="pending">Perlu Validasi Baru (<?= $jml_kamar_pending ?>)</option>
          <option value="revisi">Perlu Revisi (<?= $jml_kamar_revisi ?>)</option>
          <option value="disetujui">Sudah Disetujui (<?= $jml_kamar_aktif ?>)</option>
          <option value="semua">Semua Kamar (<?= count($daftar_kamar) ?>)</option>
        </select>
      </div>
    </div>

    <!-- STATE KOSONG SAAT ANTREAN VERIFIKASI SUDAH BERSIH -->
    <div id="emptyAntreanBox" class="<?= $jml_antrean > 0 ? 'hidden' : '' ?> bg-surface-container-lowest p-12 rounded-2xl shadow-sm text-center space-y-2 border border-outline-variant/40">
      <div class="w-12 h-12 rounded-2xl bg-surface-variant text-primary flex items-center justify-center mx-auto">
        <span class="material-symbols-outlined text-[26px]">task_alt</span>
      </div>
      <h3 class="text-base font-bold text-on-surface">Semua Antrean Kamar Sudah Diverifikasi</h3>
      <p class="text-xs text-outline max-w-md mx-auto">Tidak ada pengajuan unit kamar baru yang menunggu validasi saat ini. Gunakan filter <strong>Sudah Disetujui</strong> di pojok kanan atas untuk melihat kamar yang telah tayang.</p>
    </div>

    <!-- DAFTAR KARTU VALIDASI KAMAR (DATA RIIL) -->
    <?php if (!empty($daftar_kamar)): ?>
      <div id="gridKamarValidasi" class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <?php foreach ($daftar_kamar as $km):
          $id_k  = (int)$km['id_kamar'];
          $id_p  = (int)$km['id_properti'];
          $st_v  = strtolower((string)$km['status_validasi']);
          $fotos = $foto_by_kamar[$id_k] ?? $foto_by_kamar[$id_p] ?? [];
          $is_antrean_awal = in_array($st_v, ['pending', 'revisi'], true);
        ?>
        <div class="card-kamar bg-surface-container-lowest rounded-xl p-5 shadow-sm flex flex-col justify-between gap-4 border <?= $st_v === 'pending' ? 'border-primary/40' : 'border-transparent' ?>"
             style="<?= $is_antrean_awal ? '' : 'display:none;' ?>"
             data-search="<?= e(strtolower($km['nama_kamar'] . ' ' . $km['nama_kos'] . ' ' . $km['nama_mitra'] . ' ' . $km['lokasi'])) ?>"
             data-status="<?= e($st_v) ?>">
          <div class="space-y-3">
            <!-- Header Kartu -->
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="flex items-center gap-2">
                  <span class="text-[11px] font-mono font-semibold text-primary">#KMR-<?= $id_k ?></span>
                  <span class="px-2 py-0.5 rounded bg-surface-container-low text-on-surface-variant text-[10px] font-semibold uppercase"><?= e($km['tipe_kos']) ?></span>
                </div>
                <h2 class="text-base font-bold text-on-surface mt-0.5"><?= e($km['nama_kamar']) ?> &mdash; <?= e($km['nama_kos']) ?></h2>
                <p class="text-xs text-outline"><?= e($km['lokasi']) ?> &bull; Pemilik: <strong class="text-on-surface"><?= e($km['nama_mitra']) ?></strong></p>
              </div>

              <?php if ($st_v === 'disetujui'): ?>
                <span class="px-2.5 py-1 rounded-full bg-surface-variant text-primary text-xs font-semibold shrink-0">Disetujui</span>
              <?php elseif ($st_v === 'revisi'): ?>
                <span class="px-2.5 py-1 rounded-full bg-error-container text-on-error-container text-xs font-semibold shrink-0">Perlu Revisi</span>
              <?php else: ?>
                <span class="px-2.5 py-1 rounded-full bg-tertiary-fixed text-on-tertiary-fixed-variant text-xs font-semibold shrink-0">Pending Survei</span>
              <?php endif; ?>
            </div>

            <!-- Info Harga, Stok & Fasilitas -->
            <div class="grid grid-cols-2 gap-2.5 p-3 rounded-xl bg-surface-container-low text-xs">
              <div>
                <span class="text-outline block text-[11px]">Tarif Sewa / Bulan</span>
                <span class="font-bold text-primary text-sm"><?= rupiah($km['harga_sewa']) ?></span>
              </div>
              <div>
                <span class="text-outline block text-[11px]">Ketersediaan Unit</span>
                <span class="font-bold text-on-surface text-sm"><?= (int)$km['stok_kamar'] ?> Kamar Kosong</span>
              </div>
              <div class="col-span-2 pt-1.5 border-t border-outline-variant/40">
                <span class="text-outline block text-[11px]">Fasilitas Terdaftar</span>
                <span class="text-on-surface font-medium"><?= e($km['fasilitas']) ?></span>
              </div>
            </div>

            <!-- Galeri Foto Asli dari tabel foto_kamar -->
            <div>
              <span class="text-[11px] font-semibold text-outline block mb-1.5">Dokumentasi Foto Unit (<?= count($fotos) ?> file)</span>
              <?php if (!empty($fotos)): ?>
                <div class="grid grid-cols-3 gap-2">
                  <?php foreach (array_slice($fotos, 0, 3) as $ft):
                    $clean_ft = ltrim((string)$ft, '/');
                    if (strpos($clean_ft, 'http') === 0 || strpos($clean_ft, '../') === 0) {
                        $src = $clean_ft;
                    } elseif (strpos($clean_ft, 'uploads/') === 0) {
                        $src = '../' . $clean_ft;
                    } else {
                        $src = '../uploads/' . $clean_ft;
                    }
                  ?>
                    <img src="<?= e($src) ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=400&q=80'"
                         alt="Foto Kamar"
                         class="h-20 w-full object-cover rounded-lg bg-surface-container">
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="p-3 rounded-lg bg-surface-container-low/70 text-[11px] text-outline flex items-center gap-1.5">
                  <span class="material-symbols-outlined text-[16px]">image_not_supported</span>
                  <span>Belum ada foto unggahan tambahan pada tabel <code>foto_kamar</code>.</span>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($km['catatan_validasi'] !== ''): ?>
              <div class="p-2.5 rounded-lg bg-tertiary-fixed/40 text-on-tertiary-fixed-variant text-xs">
                <strong>Catatan Admin:</strong> <?= e($km['catatan_validasi']) ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Form Aksi Validasi -->
          <form method="POST" action="validasi_kamar.php" class="pt-3 border-t border-outline-variant/30 flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
            <input type="hidden" name="id_kamar" value="<?= $id_k ?>">
            <input type="text" name="catatan_validasi" placeholder="Catatan survei / alasan revisi (opsional)..." class="flex-1 h-9 px-3 rounded-lg bg-surface-container-low text-xs text-on-surface outline-none focus:ring-2 focus:ring-primary/20">
            <div class="flex items-center gap-2 shrink-0">
              <button type="submit" name="action" value="revisi_kamar" class="h-9 px-3 rounded-lg bg-surface-container-low text-tertiary hover:bg-tertiary-fixed/50 text-xs font-semibold transition-all">
                Minta Revisi
              </button>
              <button type="submit" name="action" value="setujui_kamar" class="h-9 px-3.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container text-xs font-semibold shadow-xs transition-all">
                Setujui &amp; Tayangkan
              </button>
            </div>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<script>
function filterKamarCards() {
  const q = (document.getElementById('searchKamarInput').value || '').toLowerCase().trim();
  const st = document.getElementById('filterStatusKamar').value;
  let visibleCount = 0;

  document.querySelectorAll('.card-kamar').forEach(card => {
    const s = card.getAttribute('data-search') || '';
    const rSt = card.getAttribute('data-status') || '';
    const okText = !q || s.includes(q);
    const okSt = st === 'semua'
      || (st === 'antrean' && (rSt === 'pending' || rSt === 'revisi'))
      || rSt === st;

    const show = okText && okSt;
    card.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });

  const emptyBox = document.getElementById('emptyAntreanBox');
  if (emptyBox) {
    emptyBox.style.display = visibleCount === 0 ? '' : 'none';
  }
}

window.addEventListener('DOMContentLoaded', filterKamarCards);
</script>
</body>
</html>