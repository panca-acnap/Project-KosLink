<?php
require __DIR__ . '/../koneksi.php';

// Cek isi tabel admin yang ada di database
$q = mysqli_query($conn, "SELECT * FROM admin");
if (!$q) {
    die("Tabel 'admin' tidak ditemukan: " . mysqli_error($conn));
}

echo "<h3>Data Tabel Admin Saat Ini:</h3><pre>";
while ($row = mysqli_fetch_assoc($q)) {
    print_r($row);
}
echo "</pre>";