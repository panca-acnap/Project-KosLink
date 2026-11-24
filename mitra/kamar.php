<?php
require __DIR__ . '/../koneksi.php';
require_role('mitra');
expire_pending_transactions();
$id_mitra = (int)$_SESSION['id_mitra'];

$rows = db_all("SELECT k.*, p.nama_kos FROM Kamar k JOIN Properti_Kos p ON k.id_properti = p.id_properti
                WHERE p.id_mitra = ? ORDER BY p.nama_kos, k.nama_kamar", 'i', [$id_mitra]);

dash_start('Manajemen Kamar', 'mitra', 'kamar');
?>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Manajemen Kamar</h1>
            <a href="kamar_form.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">+ Tambah Kamar</a>
        </div>

        <div class="bg-white rounded-xl border shadow-sm">
            <table class="w-full text-left border-collapse text-sm">
                <tr class="bg-gray-50 border-b text-gray-500">
                    <th class="px-6 py-3 font-medium">Nama Kamar</th>
                    <th class="px-6 py-3 font-medium">Properti</th>
                    <th class="px-6 py-3 font-medium">Harga</th>
                    <th class="px-6 py-3 font-medium">Sisa</th>
                    <th class="px-6 py-3 font-medium">Aksi</th>
                </tr>
                <?php foreach ($rows as $row): ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-6 py-4 font-bold text-gray-800"><?= e($row['nama_kamar']) ?></td>
                    <td class="px-6 py-4"><?= e($row['nama_kos']) ?></td>
                    <td class="px-6 py-4 text-blue-600 font-semibold"><?= rupiah($row['harga_bulan']) ?></td>
                    <td class="px-6 py-4"><?= (int)$row['sisa_kamar'] ?></td>
                    <td class="px-6 py-4"><div class="flex gap-4">
                        <a href="kamar_form.php?id=<?= (int)$row['id_kamar'] ?>" class="text-blue-600 hover:underline">Edit</a>
                        <form method="POST" action="kamar_hapus.php" onsubmit="return confirm('Hapus kamar ini?')">
                            <?= csrf_field() ?><input type="hidden" name="id_kamar" value="<?= (int)$row['id_kamar'] ?>">
                            <button class="text-red-600 hover:underline">Hapus</button>
                        </form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">Belum ada kamar.</td></tr><?php endif; ?>
            </table>
        </div>
<?php dash_end();
