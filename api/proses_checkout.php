<?php
require __DIR__ . '/../koneksi.php';
require_post();
require_role('penyewa', '../');

$id_pengguna = (int)$_SESSION['id_pengguna'];
$id_kamar    = int_param($_POST['id_kamar'] ?? null);
$durasi      = (int)($_POST['durasi'] ?? 0);
$tgl         = (string)($_POST['tgl_masuk'] ?? '');

$d = DateTime::createFromFormat('Y-m-d', $tgl);
$tglValid = $d && $d->format('Y-m-d') === $tgl && $tgl >= date('Y-m-d');

if (!$id_kamar || !in_array($durasi, [1, 3, 6, 12], true) || !$tglValid) {
    flash('error', 'Data pemesanan tidak valid. Pilih kamar, tanggal masuk (hari ini atau setelahnya), dan durasi.');
    redirect($_SERVER['HTTP_REFERER'] ?? '../index.php');
}

expire_pending_transactions();

$conn->begin_transaction();
try {
    // Kunci baris kamar supaya dua pemesan tidak bisa mengambil stok terakhir bersamaan
    $kamar = db_one("SELECT k.id_kamar, k.id_properti, k.harga_bulan, k.sisa_kamar, p.id_mitra
                     FROM Kamar k JOIN Properti_Kos p ON k.id_properti = p.id_properti
                     WHERE k.id_kamar = ? FOR UPDATE", 'i', [$id_kamar]);
    if (!$kamar) {
        $conn->rollback();
        flash('error', 'Kamar tidak ditemukan.');
        redirect('../index.php');
    }
    if ((int)$kamar['sisa_kamar'] <= 0) {
        $conn->rollback();
        flash('error', 'Maaf, kamar ini sudah penuh.');
        redirect('../detail.php?id=' . (int)$kamar['id_properti']);
    }

    $total  = (float)$kamar['harga_bulan'] * $durasi;   // dihitung di server, bukan dari form
    $id_trx = 'TRX' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
    $link   = 'penyewa/bayar.php?id=' . $id_trx;

    db_exec("INSERT INTO Transaksi (id_transaksi, id_pengguna, id_kamar, id_mitra, tanggal_masuk, durasi_bulan, total_harga, status_pembayaran, link_pembayaran)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
        'siiisids', [$id_trx, $id_pengguna, $id_kamar, (int)$kamar['id_mitra'], $tgl, $durasi, $total, $link]);
    db_exec("UPDATE Kamar SET sisa_kamar = sisa_kamar - 1 WHERE id_kamar = ?", 'i', [$id_kamar]);

    $conn->commit();
} catch (Throwable $t) {
    $conn->rollback();
    error_log('checkout: ' . $t->getMessage());
    flash('error', 'Pemesanan gagal diproses. Silakan coba lagi.');
    redirect('../index.php');
}

flash('success', 'Pemesanan dibuat. Selesaikan pembayaran dalam 24 jam.');
redirect('../penyewa/bayar.php?id=' . urlencode($id_trx));
