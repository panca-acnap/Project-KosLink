<?php
require '../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['role']) || $_SESSION['role'] === 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

$id_user   = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? 1);
$nama_user = $_SESSION['nama'] ?? 'Penyewa KosLink';

function getColsGateway($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

$metode_awal = strtolower(trim($_GET['metode'] ?? 'qris'));
if (!in_array($metode_awal, ['qris', 'va', 'paylater', 'kartu'], true)) {
    $metode_awal = 'qris';
}
if ($metode_awal === 'kartu') {
    $metode_awal = 'va';
}

// Ambil data hunian / kamar target yang akan dibayar
$id_sewa_param = isset($_GET['id_sewa']) ? (int)$_GET['id_sewa'] : 0;
if ($id_sewa_param > 0) {
    $q_hunian = @mysqli_query($conn, "SELECT * FROM penyewa WHERE id_penyewa = $id_sewa_param LIMIT 1");
} else {
    $q_hunian = @mysqli_query($conn, "SELECT * FROM penyewa WHERE id_pengguna = $id_user ORDER BY id_penyewa DESC LIMIT 1");
}
$hunian = ($q_hunian && mysqli_num_rows($q_hunian) > 0) ? mysqli_fetch_assoc($q_hunian) : null;
$id_target_cek = (int)($hunian['id_penyewa'] ?? $id_sewa_param ?? 0);

// CEK ATURAN 1 PENYEWA = MAKSIMAL 1 KOS AKTIF (Berdasarkan id_pengguna & no_hp)
$sudah_punya_kos = false;
$info_kos_aktif  = null;

$cond_cek = [];
if ($id_user > 0) {
    $cond_cek[] = "id_pengguna = $id_user";
}
$hp_cek = preg_replace('/[^0-9]/', '', $hunian['no_hp'] ?? '');
if ($hp_cek !== '') {
    $hp_esc = mysqli_real_escape_string($conn, $hp_cek);
    $cond_cek[] = "REPLACE(REPLACE(REPLACE(no_hp, '-', ''), ' ', ''), '+', '') = '$hp_esc'";
}

if (!empty($cond_cek)) {
    $where_aktif = '(' . implode(' OR ', $cond_cek) . ')';
    $q_cek_aktif = @mysqli_query(
        $conn,
        "SELECT id_penyewa, no_kamar, jatuh_tempo 
         FROM penyewa 
         WHERE $where_aktif 
           AND id_penyewa != $id_target_cek 
           AND LOWER(TRIM(COALESCE(status_hunian, ''))) = 'aktif' 
         LIMIT 1"
    );
    if ($q_cek_aktif && mysqli_num_rows($q_cek_aktif) > 0) {
        $sudah_punya_kos = true;
        $info_kos_aktif  = mysqli_fetch_assoc($q_cek_aktif);
    }
}

$biaya_va   = ($metode_awal === 'va') ? 2500 : 0;
$nominal    = (int)($hunian['nominal_sewa'] ?? 1500000) + $biaya_va;
$skema_sewa = $hunian['skema_kontrak'] ?? '1 Bulan';
$pesan_tolak = '';

// Jika tombol "Saya Sudah Membayar" diklik
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['konfirmasi_bayar'])) {
    if ($sudah_punya_kos) {
        $pesan_tolak = "Transaksi ditolak: Akun atau data pribadi Anda masih terdaftar aktif di kamar " . ($info_kos_aktif['no_kamar'] ?? 'lain') . ". Satu penyewa hanya diperbolehkan menyewa maksimal 1 kos aktif.";
    } else {
        $id_target    = (int)($hunian['id_penyewa'] ?? $id_sewa_param ?? 0);
        $metode_final = mysqli_real_escape_string($conn, $_POST['metode_terpilih'] ?? $metode_awal);

        if ($id_target > 0) {
            // Aktifkan hunian baru & tandai Lunas
            @mysqli_query(
                $conn,
                "UPDATE penyewa 
                 SET id_pengguna = $id_user,
                     status_bayar = 'Lunas', 
                     status_escrow = 'Escrow Hold', 
                     status_hunian = 'Aktif' 
                 WHERE id_penyewa = $id_target"
            );

            // Cari ID Kamar, ID Properti, dan ID Mitra yang valid
            $id_kamar_trx = (int)($hunian['id_kamar'] ?? 0);
            $id_prop_trx  = (int)($hunian['id_properti'] ?? 0);
            $id_mitra_trx = 0;

            if ($id_kamar_trx > 0) {
                $q_rel = @mysqli_query(
                    $conn,
                    "SELECT k.id_properti, p.id_mitra 
                     FROM kamar k 
                     LEFT JOIN properti_kos p ON p.id_properti = k.id_properti 
                     WHERE k.id_kamar = $id_kamar_trx LIMIT 1"
                );
                if ($q_rel && ($rel = mysqli_fetch_assoc($q_rel))) {
                    if ($id_prop_trx <= 0) {
                        $id_prop_trx = (int)($rel['id_properti'] ?? 0);
                    }
                    $id_mitra_trx = (int)($rel['id_mitra'] ?? 0);
                }
            } elseif ($id_prop_trx > 0) {
                $q_prop_m = @mysqli_query($conn, "SELECT id_mitra FROM properti_kos WHERE id_properti = $id_prop_trx LIMIT 1");
                if ($q_prop_m && ($pm = mysqli_fetch_assoc($q_prop_m))) {
                    $id_mitra_trx = (int)($pm['id_mitra'] ?? 0);
                }
            }

            $valid_mitra = false;
            if ($id_mitra_trx > 0) {
                $q_cek_m = @mysqli_query($conn, "SELECT id_mitra FROM mitra WHERE id_mitra = $id_mitra_trx LIMIT 1");
                if ($q_cek_m && mysqli_num_rows($q_cek_m) > 0) {
                    $valid_mitra = true;
                }
            }
            if (!$valid_mitra) {
                $q_fallback_m = @mysqli_query($conn, "SELECT id_mitra FROM mitra ORDER BY id_mitra ASC LIMIT 1");
                if ($q_fallback_m && ($fm = mysqli_fetch_assoc($q_fallback_m))) {
                    $id_mitra_trx = (int)$fm['id_mitra'];
                }
            }

            if ($id_prop_trx <= 0) {
                $q_fallback_p = @mysqli_query($conn, "SELECT id_properti FROM properti_kos ORDER BY id_properti ASC LIMIT 1");
                if ($q_fallback_p && ($fp = mysqli_fetch_assoc($q_fallback_p))) {
                    $id_prop_trx = (int)$fp['id_properti'];
                }
            }

            // Kurangi stok sisa_kamar secara real-time
            if ($id_kamar_trx > 0) {
                @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `stok_kamar_log` (
                    `id_log` INT AUTO_INCREMENT PRIMARY KEY,
                    `id_penyewa` INT NOT NULL,
                    `id_kamar` INT NOT NULL,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_penyewa_kamar` (`id_penyewa`, `id_kamar`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $q_log_cek = @mysqli_query($conn, "SELECT id_log FROM stok_kamar_log WHERE id_penyewa = $id_target AND id_kamar = $id_kamar_trx LIMIT 1");
                if (!$q_log_cek || mysqli_num_rows($q_log_cek) === 0) {
                    $cols_k = getColsGateway($conn, 'kamar');
                    $col_sisa = in_array('sisa_kamar', $cols_k, true) ? 'sisa_kamar' : 'jumlah_unit';
                    @mysqli_query($conn, "UPDATE kamar SET `$col_sisa` = GREATEST(0, `$col_sisa` - 1) WHERE id_kamar = $id_kamar_trx");
                    if (in_array('status_kamar', $cols_k, true)) {
                        @mysqli_query($conn, "UPDATE kamar SET status_kamar = CASE WHEN `$col_sisa` > 0 THEN 'Tersedia' ELSE 'Penuh' END WHERE id_kamar = $id_kamar_trx");
                    }
                    @mysqli_query($conn, "INSERT IGNORE INTO stok_kamar_log (id_penyewa, id_kamar) VALUES ($id_target, $id_kamar_trx)");
                }
            }

            // Catat ke tabel transaksi
            $cols_trx = getColsGateway($conn, 'transaksi');
            if (!empty($cols_trx) && $hunian) {
                $tgl_masuk_trx  = mysqli_real_escape_string($conn, $hunian['tanggal_masuk'] ?? date('Y-m-d'));
                $tgl_keluar_trx = mysqli_real_escape_string($conn, $hunian['jatuh_tempo'] ?? date('Y-m-d', strtotime('+30 days')));
                $now_trx        = date('Y-m-d H:i:s');

                $fields = [];
                $values = [];

                if (in_array('id_pengguna', $cols_trx, true) && $id_user > 0)      { $fields[] = '`id_pengguna`'; $values[] = $id_user; }
                if (in_array('id_penyewa', $cols_trx, true) && $id_target > 0)     { $fields[] = '`id_penyewa`';  $values[] = $id_target; }
                if (in_array('id_mitra', $cols_trx, true) && $id_mitra_trx > 0)    { $fields[] = '`id_mitra`';    $values[] = $id_mitra_trx; }
                if (in_array('id_properti', $cols_trx, true) && $id_prop_trx > 0)  { $fields[] = '`id_properti`'; $values[] = $id_prop_trx; }
                if (in_array('id_kamar', $cols_trx, true) && $id_kamar_trx > 0)    { $fields[] = '`id_kamar`';    $values[] = $id_kamar_trx; }
                if (in_array('tanggal_masuk', $cols_trx, true))     { $fields[] = '`tanggal_masuk`';     $values[] = "'$tgl_masuk_trx'"; }
                if (in_array('tanggal_keluar', $cols_trx, true))    { $fields[] = '`tanggal_keluar`';    $values[] = "'$tgl_keluar_trx'"; }
                if (in_array('total_harga', $cols_trx, true))       { $fields[] = '`total_harga`';       $values[] = $nominal; }
                if (in_array('metode_pembayaran', $cols_trx, true)) { $fields[] = '`metode_pembayaran`'; $values[] = "'$metode_final'"; }
                if (in_array('status_pembayaran', $cols_trx, true)) { $fields[] = '`status_pembayaran`'; $values[] = "'lunas'"; }
                if (in_array('tanggal_transaksi', $cols_trx, true)) { $fields[] = '`tanggal_transaksi`'; $values[] = "'$now_trx'"; }

                if (!empty($fields)) {
                    @mysqli_query($conn, "INSERT INTO transaksi (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")");
                }
            }
        }

        header("Location: sukses.php?id_sewa=" . $id_target);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran Gateway - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <header class="bg-white border-b border-slate-200/85 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2.5">
                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                    <i class="fa-solid fa-building text-sm"></i>
                </div>
                <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
            </a>
            <div class="flex items-center gap-3 text-xs font-bold text-slate-600">
                <a href="dashboard.php" class="hover:text-blue-600">Kembali ke Dashboard</a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 flex-1">

        <?php if ($sudah_punya_kos || $pesan_tolak !== ''): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-5 py-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-ban text-rose-600 text-lg mt-0.5"></i>
                <div>
                    <p class="font-extrabold text-rose-900">Batas Maksimal 1 Kos Aktif Terpenuhi</p>
                    <p class="mt-0.5 leading-relaxed">
                        <?= $pesan_tolak !== '' ? htmlspecialchars($pesan_tolak) : 'Anda saat ini masih memiliki hunian aktif di kamar <strong>' . htmlspecialchars($info_kos_aktif['no_kamar'] ?? '-') . '</strong>. Selesaikan masa sewa saat ini terlebih dahulu sebelum menyewa kos baru.' ?>
                    </p>
                </div>
            </div>
            <a href="dashboard.php" class="shrink-0 bg-rose-600 hover:bg-rose-700 text-white font-extrabold px-4 py-2.5 rounded-xl transition text-center">
                Lihat Kos Aktif Saya
            </a>
        </div>
        <?php endif; ?>

        <!-- Stepper -->
        <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm">
            <div class="relative flex items-center justify-between max-w-xl mx-auto">
                <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 h-1 bg-slate-100 z-0"></div>
                <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-blue-600 z-0 w-full"></div>

                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-extrabold"><i class="fa-solid fa-check"></i></div>
                    <span class="text-xs font-bold text-slate-600">Langkah 1: Data</span>
                </div>
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-extrabold shadow-md">2</div>
                    <span class="text-xs font-extrabold text-blue-600">Langkah 2: Pembayaran</span>
                </div>
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-xs font-extrabold">3</div>
                    <span class="text-xs font-bold text-slate-400">Langkah 3: Selesai</span>
                </div>
            </div>
        </div>

        <!-- Warning Countdown -->
        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-5 py-3.5 rounded-2xl flex items-center justify-between text-xs font-bold">
            <div class="flex items-center gap-2.5">
                <i class="fa-solid fa-clock text-amber-600 text-base"></i>
                <span>Selesaikan Pembayaran Dalam: <strong class="font-mono text-sm text-rose-600" id="countdown">14:56</strong></span>
            </div>
            <span class="text-[11px] text-slate-500 font-medium">Nomor Invoice: #KL-<?= date('Y') ?>-<?= str_pad((string)($hunian['id_penyewa'] ?? 88492), 5, '0', STR_PAD_LEFT) ?></span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Kiri: Pilih Metode Pembayaran -->
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-900">Pilih Metode Pembayaran</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Transaksi langsung diproses secara otomatis 24/7 dengan verifikasi instan.</p>
                    </div>

                    <!-- 1. QRIS -->
                    <div id="box-qris" onclick="pilihGateway('qris')" class="border-2 border-blue-600 bg-blue-50/30 rounded-2xl p-5 space-y-4 cursor-pointer transition">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center text-sm font-bold">
                                    <i class="fa-solid fa-qrcode"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-extrabold text-slate-900">QRIS (Seluruh E-Wallet &amp; M-Banking)</p>
                                    <p class="text-[10px] text-slate-500">BCA QR, GoPay, OVO, ShopeePay, DANA, Livin' by Mandiri</p>
                                </div>
                            </div>
                            <span id="check-qris" class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px]"><i class="fa-solid fa-check"></i></span>
                        </div>

                        <div id="detail-qris" class="bg-white border border-slate-200 rounded-2xl p-6 flex flex-col sm:flex-row items-center gap-6" onclick="event.stopPropagation()">
                            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200 text-center">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=KOSLINK_PAYMENT_SIMULATION" alt="QRIS" class="w-36 h-36 mx-auto">
                                <span class="text-[9px] font-bold text-slate-400 mt-2 block">MID: ID10124853921049</span>
                            </div>

                            <div class="space-y-3 w-full text-xs">
                                <div>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase">Jumlah Yang Harus Dibayar</span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-xl font-extrabold text-blue-600">Rp <?= number_format($nominal, 0, ',', '.') ?></span>
                                        <button type="button" onclick="salinTeks('<?= $nominal ?>', 'Nominal tagihan disalin!')" class="bg-slate-100 hover:bg-slate-200 px-2.5 py-1 rounded-lg text-[10px] font-bold text-slate-600">Salin</button>
                                    </div>
                                </div>

                                <div class="space-y-1.5 text-slate-600 text-[11px]">
                                    <p class="font-extrabold text-slate-900">Panduan Bayar Cepat:</p>
                                    <ol class="list-decimal list-inside space-y-1 text-slate-500">
                                        <li>Buka aplikasi Mobile Banking atau E-Wallet Anda.</li>
                                        <li>Pilih menu <strong>Pindai / Scan QRIS</strong>.</li>
                                        <li>Arahkan kamera ke QR di samping.</li>
                                        <li>Periksa nama merchant: <strong>KOSLINK INDONESIA REKBER</strong>.</li>
                                        <li>Masukkan PIN dan konfirmasi pembayaran.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Virtual Account Bank -->
                    <div id="box-va" onclick="pilihGateway('va')" class="border border-slate-200 bg-white rounded-2xl p-5 space-y-4 cursor-pointer transition">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center text-sm font-bold" id="icon-va">
                                    <i class="fa-solid fa-building-columns"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-extrabold text-slate-900">Virtual Account Bank (BCA, Mandiri, BNI, BRI, Permata)</p>
                                    <p class="text-[10px] text-slate-500">Transfer otomatis tanpa perlu konfirmasi manual</p>
                                </div>
                            </div>
                            <span id="check-va" class="w-5 h-5 rounded-full border border-slate-300 text-transparent flex items-center justify-center text-[10px]"><i class="fa-solid fa-check"></i></span>
                        </div>

                        <div id="detail-va" class="hidden bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs" onclick="event.stopPropagation()">
                            <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50 border border-slate-200/80 rounded-xl p-4">
                                <div>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Nomor Virtual Account (Rekening Tujuan)</span>
                                    <div class="flex items-center gap-2.5 mt-1">
                                        <span id="no-va-display" class="font-mono text-lg font-extrabold text-slate-900 tracking-wider">1234567890</span>
                                        <button type="button" onclick="salinTeks('1234567890', 'Nomor Virtual Account 1234567890 disalin!')" class="bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-3 py-1 rounded-lg text-[10px] font-extrabold inline-flex items-center gap-1">
                                            <i class="fa-regular fa-copy"></i> Salin No. Rek
                                        </button>
                                    </div>
                                    <p class="text-[10px] text-slate-500 mt-1">Atas Nama: <strong>KOSLINK INDONESIA REKBER</strong></p>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Total Tagihan</span>
                                    <span class="text-base font-extrabold text-blue-600">Rp <?= number_format($nominal, 0, ',', '.') ?></span>
                                </div>
                            </div>

                            <div class="space-y-1.5 text-[11px] text-slate-600">
                                <p class="font-extrabold text-slate-900">Cara Pembayaran Virtual Account:</p>
                                <ol class="list-decimal list-inside space-y-1 text-slate-500">
                                    <li>Buka aplikasi M-Banking / ATM / Internet Banking bank Anda.</li>
                                    <li>Pilih menu <strong>Transfer &gt; Virtual Account</strong>.</li>
                                    <li>Masukkan nomor rekening VA <strong class="font-mono text-slate-800">1234567890</strong>.</li>
                                    <li>Pastikan nominal sesuai dan selesaikan transaksi.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Cicilan & PayLater -->
                    <div id="box-paylater" onclick="pilihGateway('paylater')" class="border border-slate-200 bg-white rounded-2xl p-5 space-y-4 cursor-pointer transition">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center text-sm font-bold" id="icon-paylater">
                                    <i class="fa-solid fa-wallet"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-extrabold text-slate-900">Cicilan &amp; PayLater (Kredivo, ShopeePayLater, Akulaku)</p>
                                    <p class="text-[10px] text-slate-500">Bayar 30 hari atau cicilan bulanan ringan</p>
                                </div>
                            </div>
                            <span id="check-paylater" class="w-5 h-5 rounded-full border border-slate-300 text-transparent flex items-center justify-center text-[10px]"><i class="fa-solid fa-check"></i></span>
                        </div>

                        <div id="detail-paylater" class="hidden bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs" onclick="event.stopPropagation()">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1.5">Pilih Penyedia PayLater</label>
                                <select id="provider-paylater" onchange="hitungCicilanPayLater()" class="w-full sm:w-72 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 bg-slate-50">
                                    <option value="Kredivo">Kredivo</option>
                                    <option value="ShopeePayLater">ShopeePayLater</option>
                                    <option value="Akulaku PayLater">Akulaku PayLater</option>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                <label class="border border-blue-600 bg-blue-50/40 rounded-xl p-3 cursor-pointer">
                                    <input type="radio" name="tenor_paylater" value="1" checked onchange="hitungCicilanPayLater()" class="mr-1.5 accent-blue-600">
                                    <span class="font-extrabold text-slate-900">Bayar 30 Hari</span>
                                    <p id="cicilan-1" class="text-[11px] font-bold text-blue-600 mt-1"></p>
                                </label>
                                <label class="border border-slate-200 rounded-xl p-3 cursor-pointer">
                                    <input type="radio" name="tenor_paylater" value="3" onchange="hitungCicilanPayLater()" class="mr-1.5 accent-blue-600">
                                    <span class="font-extrabold text-slate-900">Cicilan 3 Bulan</span>
                                    <p id="cicilan-3" class="text-[11px] font-bold text-slate-700 mt-1"></p>
                                </label>
                                <label class="border border-slate-200 rounded-xl p-3 cursor-pointer">
                                    <input type="radio" name="tenor_paylater" value="6" onchange="hitungCicilanPayLater()" class="mr-1.5 accent-blue-600">
                                    <span class="font-extrabold text-slate-900">Cicilan 6 Bulan</span>
                                    <p id="cicilan-6" class="text-[11px] font-bold text-slate-700 mt-1"></p>
                                </label>
                            </div>

                            <p id="info-paylater" class="text-[11px] text-slate-500 bg-slate-50 border border-slate-200/70 rounded-xl p-3"></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kanan: Ringkasan & Tombol Konfirmasi -->
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 sticky top-20 text-xs">
                    <h3 class="text-sm font-extrabold text-slate-900 border-b border-slate-100 pb-3">Ringkasan Pemesanan</h3>

                    <div class="space-y-2 text-slate-600">
                        <div class="flex justify-between">
                            <span>Nama Penyewa</span>
                            <span class="font-bold text-slate-900"><?= htmlspecialchars($nama_user) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Unit Kamar</span>
                            <span class="font-bold text-slate-900"><?= htmlspecialchars($hunian['no_kamar'] ?? 'Kamar Pilihan') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Metode Dipilih</span>
                            <span id="ringkasan-metode" class="font-bold text-blue-600">QRIS Instan</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Durasi Sewa</span>
                            <span class="font-bold text-slate-900"><?= htmlspecialchars($skema_sewa) ?></span>
                        </div>
                        <div class="pt-3 border-t border-slate-200 flex justify-between items-baseline">
                            <span class="font-extrabold text-slate-900 text-sm">Total Pembayaran</span>
                            <span class="text-lg font-extrabold text-blue-600">Rp <?= number_format($nominal, 0, ',', '.') ?></span>
                        </div>
                    </div>

                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="metode_terpilih" id="input-metode-terpilih" value="<?= htmlspecialchars($metode_awal) ?>">
                        <button type="submit" name="konfirmasi_bayar" value="1" <?= $sudah_punya_kos ? 'disabled' : '' ?> class="w-full <?= $sudah_punya_kos ? 'bg-slate-300 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700' ?> text-white text-xs font-extrabold py-3.5 rounded-xl transition shadow-md flex items-center justify-center gap-2">
                            <i class="fa-solid fa-circle-check"></i> Saya Sudah Membayar
                        </button>
                    </form>

                    <div class="p-3 rounded-2xl bg-blue-50/70 border border-blue-200 text-[10px] text-slate-600 space-y-1">
                        <p class="font-extrabold text-blue-900"><i class="fa-solid fa-lock text-blue-600 mr-1"></i> Rekening Bersama (Rekber) KosLink</p>
                        <p>Dana Anda aman dan hanya diteruskan ke pemilik kos setelah Anda berhasil check-in.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const totalNominal = <?= (int)$nominal ?>;
        const metodeAwal   = "<?= htmlspecialchars($metode_awal) ?>";

        function formatRp(angka) {
            return 'Rp ' + Math.round(angka).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function salinTeks(teks, pesan) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(teks).catch(() => {});
            }
            alert(pesan);
        }

        function pilihGateway(metode) {
            const list = ['qris', 'va', 'paylater'];
            const labelMap = {
                qris: 'QRIS Instan',
                va: 'Virtual Account (1234567890)',
                paylater: 'Cicilan / PayLater'
            };

            document.getElementById('input-metode-terpilih').value = metode;
            document.getElementById('ringkasan-metode').innerText = labelMap[metode] || 'QRIS Instan';

            list.forEach((m) => {
                const box = document.getElementById('box-' + m);
                const detail = document.getElementById('detail-' + m);
                const check = document.getElementById('check-' + m);
                const icon = document.getElementById('icon-' + m);

                if (m === metode) {
                    box.classList.remove('border', 'border-slate-200', 'bg-white');
                    box.classList.add('border-2', 'border-blue-600', 'bg-blue-50/30');
                    detail.classList.remove('hidden');
                    check.classList.remove('border', 'border-slate-300', 'text-transparent');
                    check.classList.add('bg-blue-600', 'text-white');
                    if (icon) {
                        icon.classList.remove('bg-slate-100', 'text-slate-700');
                        icon.classList.add('bg-blue-600', 'text-white');
                    }
                } else {
                    box.classList.remove('border-2', 'border-blue-600', 'bg-blue-50/30');
                    box.classList.add('border', 'border-slate-200', 'bg-white');
                    detail.classList.add('hidden');
                    check.classList.remove('bg-blue-600', 'text-white');
                    check.classList.add('border', 'border-slate-300', 'text-transparent');
                    if (icon) {
                        icon.classList.remove('bg-blue-600', 'text-white');
                        icon.classList.add('bg-slate-100', 'text-slate-700');
                    }
                }
            });

            if (metode === 'paylater') {
                hitungCicilanPayLater();
            }
        }

        function hitungCicilanPayLater() {
            const provider = document.getElementById('provider-paylater')?.value || 'Kredivo';
            const tenor = document.querySelector('input[name="tenor_paylater"]:checked')?.value || '1';

            document.getElementById('cicilan-1').innerText = formatRp(totalNominal) + ' / bln';
            document.getElementById('cicilan-3').innerText = formatRp(Math.ceil((totalNominal * 1.03) / 3)) + ' / bln';
            document.getElementById('cicilan-6').innerText = formatRp(Math.ceil((totalNominal * 1.06) / 6)) + ' / bln';

            const tenorLabel = tenor === '1' ? 'Bayar 30 Hari' : ('Cicilan ' + tenor + ' Bulan');
            document.getElementById('info-paylater').innerHTML =
                `Simulasi <strong>${provider}</strong> (${tenorLabel}) siap diproses. Klik <strong>Saya Sudah Membayar</strong> setelah konfirmasi di aplikasi ${provider}.`;
        }

        let time = 14 * 60 + 56;
        setInterval(() => {
            let m = Math.floor(time / 60);
            let s = time % 60;
            document.getElementById('countdown').innerText = `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
            if (time > 0) time--;
        }, 1000);

        pilihGateway(metodeAwal);
    </script>
</body>
</html>