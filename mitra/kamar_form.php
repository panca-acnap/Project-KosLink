<?php
// Tambah / edit kamar (edit bila ada ?id=). Properti dan kamar selalu dicek milik mitra yang login.
require __DIR__ . '/../koneksi.php';
require_role('mitra');
$id_mitra = (int)$_SESSION['id_mitra'];

$props = db_all("SELECT id_properti, nama_kos FROM Properti_Kos WHERE id_mitra = ? ORDER BY nama_kos", 'i', [$id_mitra]);
if (!$props) {
    flash('error', 'Tambahkan properti terlebih dahulu sebelum menambah kamar.');
    redirect('properti.php');
}
$propIds = array_map('intval', array_column($props, 'id_properti'));

$id = int_param($_GET['id'] ?? $_POST['id_kamar'] ?? null);
$kamar = null;
if ($id) {
    $kamar = db_one("SELECT k.* FROM Kamar k JOIN Properti_Kos p ON k.id_properti = p.id_properti WHERE k.id_kamar = ? AND p.id_mitra = ?", 'ii', [$id, $id_mitra]);
    if (!$kamar) {
        flash('error', 'Kamar tidak ditemukan.');
        redirect('kamar.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $id_prop   = int_param($_POST['id_properti'] ?? null);
    $nama      = trim($_POST['nama_kamar'] ?? '');
    $harga     = filter_var($_POST['harga_bulan'] ?? '', FILTER_VALIDATE_FLOAT);
    $sisa      = filter_var($_POST['sisa_kamar'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $fasilitas = trim($_POST['fasilitas'] ?? '');

    if (!$id_prop || !in_array($id_prop, $propIds, true) || $nama === '' || mb_strlen($nama) > 100
        || $harga === false || $harga <= 0 || $harga > 99999999 || $sisa === false) {
        flash('error', 'Data kamar tidak valid. Periksa nama, harga (> 0), dan sisa kamar (>= 0).');
        redirect('kamar_form.php' . ($kamar ? '?id=' . $kamar['id_kamar'] : ''));
    }

    if ($kamar) {
        $lama = (int)$kamar['id_properti'];
        db_exec("UPDATE Kamar SET id_properti=?, nama_kamar=?, harga_bulan=?, sisa_kamar=?, fasilitas=? WHERE id_kamar=?",
                'isdisi', [$id_prop, $nama, $harga, $sisa, $fasilitas, $kamar['id_kamar']]);
        if ($lama !== $id_prop) sync_harga_mulai($lama);
        flash('success', 'Kamar diperbarui.');
    } else {
        db_exec("INSERT INTO Kamar (id_properti, nama_kamar, harga_bulan, sisa_kamar, fasilitas) VALUES (?, ?, ?, ?, ?)",
                'isdis', [$id_prop, $nama, $harga, $sisa, $fasilitas]);
        flash('success', 'Kamar ditambahkan.');
    }
    sync_harga_mulai($id_prop);
    redirect('kamar.php');
}

dash_start($kamar ? 'Edit Kamar' : 'Tambah Kamar', 'mitra', 'kamar');
?>
        <h1 class="text-2xl font-bold mb-6"><?= $kamar ? 'Edit Kamar' : 'Tambah Kamar' ?></h1>
        <div class="bg-white rounded-xl border shadow-sm p-6 max-w-xl">
            <form method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <?php if ($kamar): ?><input type="hidden" name="id_kamar" value="<?= (int)$kamar['id_kamar'] ?>"><?php endif; ?>
                <div><label class="block text-sm font-medium mb-1">Properti</label>
                    <select name="id_properti" class="w-full border rounded-lg p-2">
                        <?php foreach ($props as $p): ?>
                            <option value="<?= (int)$p['id_properti'] ?>" <?= (int)($kamar['id_properti'] ?? 0) === (int)$p['id_properti'] ? 'selected' : '' ?>><?= e($p['nama_kos']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label class="block text-sm font-medium mb-1">Nama Kamar</label>
                    <input type="text" name="nama_kamar" maxlength="100" value="<?= e($kamar['nama_kamar'] ?? '') ?>" class="w-full border rounded-lg p-2" required></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium mb-1">Harga / Bulan (Rp)</label>
                        <input type="number" name="harga_bulan" min="1" step="1" value="<?= e(isset($kamar['harga_bulan']) ? (int)$kamar['harga_bulan'] : '') ?>" class="w-full border rounded-lg p-2" required></div>
                    <div><label class="block text-sm font-medium mb-1">Sisa Kamar</label>
                        <input type="number" name="sisa_kamar" min="0" step="1" value="<?= e($kamar['sisa_kamar'] ?? 1) ?>" class="w-full border rounded-lg p-2" required></div>
                </div>
                <div><label class="block text-sm font-medium mb-1">Fasilitas</label>
                    <textarea name="fasilitas" rows="3" placeholder="AC, WiFi, Kamar Mandi Dalam" class="w-full border rounded-lg p-2"><?= e($kamar['fasilitas'] ?? '') ?></textarea></div>
                <div class="flex gap-3">
                    <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Simpan</button>
                    <a href="kamar.php" class="px-4 py-2 text-sm text-gray-600">Batal</a>
                </div>
            </form>
        </div>
<?php dash_end();
