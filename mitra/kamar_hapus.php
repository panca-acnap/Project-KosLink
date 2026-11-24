<?php
require __DIR__ . '/../koneksi.php';
require_post();
require_role('mitra');
$id_mitra = (int)$_SESSION['id_mitra'];
$id = int_param($_POST['id_kamar'] ?? null);

$kamar = $id ? db_one("SELECT k.id_kamar, k.id_properti FROM Kamar k JOIN Properti_Kos p ON k.id_properti = p.id_properti
                       WHERE k.id_kamar = ? AND p.id_mitra = ?", 'ii', [$id, $id_mitra]) : null;
if (!$kamar) {
    flash('error', 'Kamar tidak ditemukan.');
    redirect('kamar.php');
}

try {
    db_exec("DELETE FROM Kamar WHERE id_kamar = ?", 'i', [$id]);
    sync_harga_mulai((int)$kamar['id_properti']);
    flash('success', 'Kamar dihapus.');
} catch (mysqli_sql_exception $ex) {
    // 1451 = masih direferensikan transaksi/keluhan (foreign key)
    if ($ex->getCode() === 1451) {
        flash('error', 'Kamar punya riwayat transaksi/keluhan sehingga tidak bisa dihapus. Set "Sisa Kamar" ke 0 agar tidak bisa dipesan.');
    } else {
        error_log('hapus kamar: ' . $ex->getMessage());
        flash('error', 'Gagal menghapus kamar.');
    }
}
redirect('kamar.php');
