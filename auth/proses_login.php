<?php
require __DIR__ . '/../koneksi.php';
require_post();

$email    = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');
$redirect = trim($_POST['redirect'] ?? $_SESSION['redirect_after_login'] ?? '');

$user    = null;
$role    = '';
$user_id = 0;

// 1. Cek Mitra
$user = db_one("SELECT * FROM mitra WHERE email = ?", 's', [$email]);
if ($user) {
    $role    = 'mitra';
    $user_id = (int)($user['id_mitra'] ?? 0);
}

// 2. Cek Admin
if (!$user) {
    $user = db_one("SELECT * FROM admin WHERE email = ? AND status_akun = 'aktif'", 's', [$email]);
    if ($user) {
        $role    = 'admin';
        $user_id = (int)($user['id_admin'] ?? 0);
    }
}

// 3. Cek Pengguna / Penyewa
if (!$user) {
    $user = db_one("SELECT * FROM pengguna WHERE email = ?", 's', [$email]);
    if ($user) {
        $role    = 'penyewa';
        $user_id = (int)($user['id_pengguna'] ?? 0);
    }
}

$password_valid = $user && (
    password_verify($password, $user['password']) ||
    hash_equals((string)$user['password'], $password)
);

if (!$password_valid) {
    flash('error', 'Email atau password salah.');
    $login_url = 'login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '');
    redirect($login_url);
}

$_SESSION['role'] = $role;
$_SESSION['csrf'] = bin2hex(random_bytes(32));

if ($role === 'mitra') {
    unset($_SESSION['redirect_after_login'], $_SESSION['pending_booking']);
    $_SESSION['id_mitra'] = $user_id;
    $_SESSION['nama']     = $user['nama_mitra'] ?? $user['nama'] ?? 'Pemilik KosLink';
    session_write_close();
    redirect('../mitra/dashboard.php');
}

if ($role === 'admin') {
    unset($_SESSION['redirect_after_login'], $_SESSION['pending_booking']);
    $_SESSION['id_admin']    = $user_id;
    $_SESSION['id_mitra']    = (int)($user['id_mitra'] ?? 0);
    $_SESSION['id_properti'] = (int)($user['id_properti'] ?? 0);
    $_SESSION['nama']        = $user['nama_admin'] ?? 'Admin KosLink';
    session_write_close();
    redirect('../admin/dashboard.php');
}

$_SESSION['id_pengguna'] = $user_id;
$_SESSION['nama']        = $user['nama_lengkap'] ?? $user['nama'] ?? 'Pengguna KosLink';
$_SESSION['no_hp']       = $user['no_hp'] ?? '';
$_SESSION['email']       = $user['email'] ?? $email;

// Simpan tujuan redirect sebelum menghapus session sementara
$pending_id_kamar = (int)($_SESSION['pending_booking']['id_kamar'] ?? 0);
unset($_SESSION['redirect_after_login']);

session_write_close();

// 1. Prioritas redirect dari parameter / session (misal: sewa.php?id=5)
if (
    $redirect !== '' &&
    strpos($redirect, 'http://') !== 0 &&
    strpos($redirect, 'https://') !== 0 &&
    strpos($redirect, '//') !== 0
) {
    redirect('../' . ltrim($redirect, '/'));
}

// 2. Fallback jika ada pending_booking
if ($pending_id_kamar > 0) {
    redirect('../sewa.php?id=' . $pending_id_kamar);
}

// 3. Default dashboard penghuni
redirect('../penyewa/dashboard.php');