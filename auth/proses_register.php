<?php
require __DIR__ . '/../koneksi.php';
require_post();

$role          = trim($_POST['role'] ?? 'penyewa');
$nama          = trim($_POST['nama'] ?? '');
$email         = trim($_POST['email'] ?? '');
$no_hp         = trim($_POST['no_hp'] ?? '');
$password      = (string)($_POST['password'] ?? '');
$password2     = (string)($_POST['password2'] ?? '');

$email_mitra   = trim($_POST['email_mitra'] ?? '');
$nama_properti = trim($_POST['nama_properti'] ?? '');

if ($nama === '' || $email === '' || $password === '') {
    flash('error', 'Semua kolom wajib diisi.');
    redirect('register.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Format email tidak valid.');
    redirect('register.php');
}

if (strlen($password) < 6) {
    flash('error', 'Password minimal 6 karakter.');
    redirect('register.php');
}

if ($password !== $password2) {
    flash('error', 'Konfirmasi password tidak cocok.');
    redirect('register.php');
}

// Cek duplikasi email
$cek_pengguna = db_one("SELECT id_pengguna FROM pengguna WHERE email = ?", 's', [$email]);
$cek_mitra    = db_one("SELECT id_mitra FROM mitra WHERE email = ?", 's', [$email]);
$cek_admin    = db_one("SELECT id_admin FROM admin WHERE email = ?", 's', [$email]);

if ($cek_pengguna || $cek_mitra || $cek_admin) {
    flash('error', 'Email sudah terdaftar di sistem.');
    redirect('register.php');
}

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

if ($role === 'admin') {
    // Cari id_mitra berdasarkan email pemilik
    $mitra_owner = db_one("SELECT id_mitra FROM Mitra WHERE email = ?", 's', [$email_mitra]);
    if (!$mitra_owner) {
        flash('error', 'Email Pemilik/Mitra tidak ditemukan di database.');
        redirect('register.php');
    }

    $id_mitra_target = (int)$mitra_owner['id_mitra'];
    $id_properti_target = null;

    if ($nama_properti !== '') {
        $safe_prop = mysqli_real_escape_string($conn, $nama_properti);
        // Sesuaikan pencarian hanya menggunakan nama_kos (atau kolom yang ada di tabel Properti_Kos Anda)
        $q_match = @mysqli_query($conn, "SELECT * FROM Properti_Kos WHERE id_mitra = $id_mitra_target AND nama_kos LIKE '%$safe_prop%' LIMIT 1");
        if ($q_match && $row_p = mysqli_fetch_assoc($q_match)) {
            $id_properti_target = (int)($row_p['id_properti'] ?? $row_p['id_kos'] ?? 0);
        }
    }

    // Insert murni sesuai kolom tabel admin di database Anda
    $stmt = mysqli_prepare($conn, "INSERT INTO admin (id_mitra, id_properti, nama_admin, email, password, no_hp, status_akun) VALUES (?, ?, ?, ?, ?, ?, 'aktif')");
    mysqli_stmt_bind_param($stmt, 'iissss', $id_mitra_target, $id_properti_target, $nama, $email, $hashed_password, $no_hp);
    
    if (mysqli_stmt_execute($stmt)) {
        flash('success', 'Akun Admin berhasil didaftarkan! Silakan masuk.');
        redirect('login.php');
    } else {
        flash('error', 'Gagal mendaftarkan admin: ' . mysqli_error($conn));
        redirect('register.php');
    }

} elseif ($role === 'mitra') {
    $stmt = mysqli_prepare($conn, "INSERT INTO Mitra (nama_mitra, email, no_hp, password) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssss', $nama, $email, $no_hp, $hashed_password);
    mysqli_stmt_execute($stmt);

    flash('success', 'Akun Pemilik Kos berhasil dibuat! Silakan login.');
    redirect('login.php');

} else {
    $stmt = mysqli_prepare($conn, "INSERT INTO pengguna (nama_lengkap, email, no_hp, password) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssss', $nama, $email, $no_hp, $hashed_password);
    mysqli_stmt_execute($stmt);

    flash('success', 'Pendaftaran berhasil! Silakan masuk.');
    redirect('login.php');
}