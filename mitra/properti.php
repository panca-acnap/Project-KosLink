<?php
require __DIR__ . '/../koneksi.php';
require_role('mitra');
$id_mitra   = (int)$_SESSION['id_mitra'];
$nama_mitra = $_SESSION['nama'] ?? 'Mitra KosLink';

function properti_table_cols(mysqli $conn): array {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `properti_kos`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

$p_cols     = properti_table_cols($conn);
$col_lokasi = in_array('lokasi', $p_cols, true) ? 'lokasi' : (in_array('alamat', $p_cols, true) ? 'alamat' : 'lokasi');
$col_tipe   = in_array('tipe_kos', $p_cols, true) ? 'tipe_kos' : (in_array('jenis_kos', $p_cols, true) ? 'jenis_kos' : (in_array('tipe', $p_cols, true) ? 'tipe' : 'tipe_kos'));
$col_harga  = in_array('harga_mulai', $p_cols, true) ? 'harga_mulai' : (in_array('harga_bulan', $p_cols, true) ? 'harga_bulan' : 'harga_mulai');
$has_desc   = in_array('deskripsi', $p_cols, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();

    // Proses Hapus Properti
    if (isset($_POST['hapus_properti'])) {
        $id_hapus = int_param($_POST['id_properti'] ?? null);

        if (!$id_hapus) {
            flash('error', 'ID properti tidak valid.');
            redirect('properti.php');
        }

        $prop_cek = db_one(
            "SELECT `id_properti` FROM `properti_kos` WHERE `id_properti` = ? AND `id_mitra` = ?",
            'ii',
            [$id_hapus, $id_mitra]
        );

        if (!$prop_cek) {
            flash('error', 'Properti tidak ditemukan atau bukan milik Anda.');
            redirect('properti.php');
        }

        $kamar_cek = db_one(
            "SELECT COUNT(*) AS total FROM `Kamar` WHERE `id_properti` = ?",
            'i',
            [$id_hapus]
        );
        if ((int)($kamar_cek['total'] ?? 0) > 0) {
            flash('error', 'Properti tidak dapat dihapus karena masih memiliki data kamar. Hapus atau pindahkan kamar terlebih dahulu.');
            redirect('properti.php');
        }

        $stmt_del = $conn->prepare("DELETE FROM `properti_kos` WHERE `id_properti` = ? AND `id_mitra` = ?");
        if ($stmt_del) {
            $stmt_del->bind_param('ii', $id_hapus, $id_mitra);
            $ok_del = $stmt_del->execute();
            $aff_del = $stmt_del->affected_rows;
            $stmt_del->close();

            if ($ok_del && $aff_del > 0) {
                flash('success', 'Properti berhasil dihapus.');
            } else {
                flash('error', 'Gagal menghapus properti.');
            }
        } else {
            flash('error', 'Gagal menyiapkan query hapus properti.');
        }
        redirect('properti.php');
    }

    // Proses Tambah / Edit Properti
    $id_prop     = int_param($_POST['id_properti'] ?? null);
    $nama        = trim($_POST['nama_kos'] ?? '');
    $lokasi      = trim($_POST['lokasi'] ?? '');
    $tipe        = strtolower(trim($_POST['tipe_kos'] ?? ''));
    $harga_mulai = (int)preg_replace('/[^0-9]/', '', (string)($_POST['harga_mulai'] ?? '0'));
    $deskripsi   = trim($_POST['deskripsi'] ?? '');

    if ($nama === '' || mb_strlen($nama) > 100 || $lokasi === '' || !in_array($tipe, ['campur', 'putra', 'putri'], true) || $harga_mulai < 0) {
        flash('error', 'Nama kos, lokasi, tipe, dan harga mulai wajib diisi dengan benar.');
        redirect('properti.php' . ($id_prop ? '?edit=' . $id_prop : ''));
    }

    $harga_val = (float)$harga_mulai;

    if ($id_prop) {
        if ($has_desc) {
            $stmt = $conn->prepare(
                "UPDATE `properti_kos`
                 SET `nama_kos` = ?, `{$col_lokasi}` = ?, `{$col_tipe}` = ?, `{$col_harga}` = ?, `deskripsi` = ?
                 WHERE `id_properti` = ? AND `id_mitra` = ?"
            );
            if (!$stmt) {
                flash('error', 'Gagal menyiapkan query update: ' . $conn->error);
                redirect('properti.php?edit=' . $id_prop);
            }
            $stmt->bind_param('sssdiii', $nama, $lokasi, $tipe, $harga_val, $deskripsi, $id_prop, $id_mitra);
        } else {
            $stmt = $conn->prepare(
                "UPDATE `properti_kos`
                 SET `nama_kos` = ?, `{$col_lokasi}` = ?, `{$col_tipe}` = ?, `{$col_harga}` = ?
                 WHERE `id_properti` = ? AND `id_mitra` = ?"
            );
            if (!$stmt) {
                flash('error', 'Gagal menyiapkan query update: ' . $conn->error);
                redirect('properti.php?edit=' . $id_prop);
            }
            $stmt->bind_param('sssdii', $nama, $lokasi, $tipe, $harga_val, $id_prop, $id_mitra);
        }

        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            flash('success', 'Properti dan harga mulai diperbarui.');
        } else {
            flash('error', 'Gagal memperbarui properti.');
        }
    } else {
        if ($has_desc) {
            $stmt = $conn->prepare(
                "INSERT INTO `properti_kos` (`id_mitra`, `nama_kos`, `{$col_lokasi}`, `{$col_tipe}`, `{$col_harga}`, `deskripsi`)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                flash('error', 'Gagal menyiapkan query insert: ' . $conn->error);
                redirect('properti.php');
            }
            $stmt->bind_param('isssds', $id_mitra, $nama, $lokasi, $tipe, $harga_val, $deskripsi);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO `properti_kos` (`id_mitra`, `nama_kos`, `{$col_lokasi}`, `{$col_tipe}`, `{$col_harga}`)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                flash('error', 'Gagal menyiapkan query insert: ' . $conn->error);
                redirect('properti.php');
            }
            $stmt->bind_param('isssd', $id_mitra, $nama, $lokasi, $tipe, $harga_val);
        }

        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            flash('success', 'Properti ditambahkan. Sekarang tambahkan kamarnya.');
        } else {
            flash('error', 'Gagal menambahkan properti.');
        }
    }
    redirect('properti.php');
}

$list = db_all(
    "SELECT p.*, (SELECT COUNT(*) FROM `kamar` k WHERE k.`id_properti` = p.`id_properti`) AS jml_kamar
     FROM `properti_kos` p WHERE p.`id_mitra` = ? ORDER BY p.`id_properti` DESC",
    'i',
    [$id_mitra]
);

$editId = int_param($_GET['edit'] ?? null);
$edit = $editId ? db_one("SELECT * FROM `properti_kos` WHERE `id_properti` = ? AND `id_mitra` = ?", 'ii', [$editId, $id_mitra]) : null;
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Properti - KosLink Mitra</title>
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
                    <a href="kelola_kos.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-door-open w-4 text-center text-slate-400"></i>
                        <span>Kelola Kamar</span>
                    </a>
                    <a href="properti.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <i class="fa-solid fa-building w-4 text-center"></i>
                        <span>Kelola Properti</span>
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
                <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200/80 text-[11px] font-bold px-3 py-1.5 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> <?= !empty($list) ? 'Aktif Terverifikasi' : 'Menunggu Properti' ?>
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

            <?= function_exists('flash_html') ? flash_html() : '' ?>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Properti Saya</h2>
                    <p class="text-xs text-slate-500 mt-1">Kelola daftar properti kos, lokasi, tipe hunian, harga mulai, dan jumlah kamar.</p>
                </div>
            </div>

            <!-- TABEL DAFTAR PROPERTI -->
            <div class="bg-white border border-slate-200/80 rounded-3xl shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 text-xs uppercase tracking-wider">
                                <th class="px-6 py-3.5 font-bold">Nama Kos</th>
                                <th class="px-6 py-3.5 font-bold">Lokasi</th>
                                <th class="px-6 py-3.5 font-bold">Tipe</th>
                                <th class="px-6 py-3.5 font-bold">Kamar</th>
                                <th class="px-6 py-3.5 font-bold">Harga Mulai</th>
                                <th class="px-6 py-3.5 font-bold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($list as $p): ?>
                            <?php
                            $val_nama   = (string)($p['nama_kos'] ?? '');
                            $val_lokasi = (string)($p[$col_lokasi] ?? $p['lokasi'] ?? $p['alamat'] ?? '-');
                            $val_tipe   = (string)($p[$col_tipe] ?? $p['tipe_kos'] ?? $p['jenis_kos'] ?? 'campur');
                            $val_harga  = (float)($p[$col_harga] ?? $p['harga_mulai'] ?? 0);
                            ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="px-6 py-4 font-bold text-slate-900"><?= e($val_nama) ?></td>
                                <td class="px-6 py-4 text-slate-600"><?= e($val_lokasi) ?></td>
                                <td class="px-6 py-4 uppercase text-xs font-extrabold text-slate-700"><?= e($val_tipe) ?></td>
                                <td class="px-6 py-4 font-semibold text-slate-700"><?= (int)($p['jml_kamar'] ?? 0) ?></td>
                                <td class="px-6 py-4 text-blue-600 font-bold"><?= rupiah($val_harga) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="inline-flex items-center gap-3">
                                        <a href="?edit=<?= (int)$p['id_properti'] ?>" class="text-blue-600 hover:underline font-bold text-xs">
                                            Edit
                                        </a>
                                        <form method="POST" action="properti.php" class="inline" onsubmit="return confirm('Yakin ingin menghapus properti <?= e(addslashes($val_nama)) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_properti" value="<?= (int)$p['id_properti'] ?>">
                                            <button type="submit" name="hapus_properti" value="1" class="text-rose-600 hover:underline font-bold text-xs cursor-pointer">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$list): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-slate-500 text-xs font-medium">Belum ada properti.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- FORM TAMBAH / EDIT PROPERTI -->
            <div class="bg-white border border-slate-200/80 rounded-3xl shadow-2xs p-6 max-w-xl">
                <?php
                $edit_lokasi = (string)($edit[$col_lokasi] ?? $edit['lokasi'] ?? $edit['alamat'] ?? '');
                $edit_tipe   = strtolower((string)($edit[$col_tipe] ?? $edit['tipe_kos'] ?? $edit['jenis_kos'] ?? 'campur'));
                $edit_harga  = isset($edit) ? (int)($edit[$col_harga] ?? $edit['harga_mulai'] ?? 0) : '';
                ?>
                <h3 class="text-base font-extrabold text-slate-900 mb-4"><?= $edit ? 'Edit Properti' : 'Tambah Properti' ?></h3>
                <form method="POST" class="space-y-4">
                    <?= csrf_field() ?>
                    <?php if ($edit): ?><input type="hidden" name="id_properti" value="<?= (int)$edit['id_properti'] ?>"><?php endif; ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Nama Kos</label>
                        <input type="text" name="nama_kos" maxlength="100" value="<?= e($edit['nama_kos'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-sm font-medium text-slate-800 focus:outline-none focus:border-blue-600" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Lokasi</label>
                        <input type="text" name="lokasi" value="<?= e($edit_lokasi) ?>" placeholder="Jimbaran, Bali" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-sm font-medium text-slate-800 focus:outline-none focus:border-blue-600" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Tipe</label>
                        <select name="tipe_kos" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-sm font-medium text-slate-800 focus:outline-none focus:border-blue-600">
                            <?php foreach (['campur', 'putra', 'putri'] as $t): ?>
                                <option value="<?= $t ?>" <?= $edit_tipe === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Harga Mulai / Bulan (Rp)</label>
                        <input type="number" name="harga_mulai" min="0" step="1000" value="<?= $edit_harga ?>" placeholder="Contoh: 1500000" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-sm font-medium text-slate-800 focus:outline-none focus:border-blue-600" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Deskripsi</label>
                        <textarea name="deskripsi" rows="3" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-sm font-medium text-slate-800 focus:outline-none focus:border-blue-600"><?= e($edit['deskripsi'] ?? '') ?></textarea>
                    </div>
                    <div class="flex items-center gap-3 pt-1">
                        <button class="bg-blue-600 text-white px-4 py-2.5 rounded-xl text-xs font-extrabold hover:bg-blue-700 transition shadow-sm">Simpan</button>
                        <?php if ($edit): ?><a href="properti.php" class="px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-900">Batal</a><?php endif; ?>
                    </div>
                </form>
            </div>

        </main>
    </div>

</body>
</html>