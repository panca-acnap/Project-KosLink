<?php
// Helper umum: session, escaping, CSRF, flash message, proteksi halaman.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/** Escape output HTML (cegah XSS). */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($angka): string
{
    return "Rp " . number_format((float)$angka, 0, ',', '.');
}

function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = [$type, $msg];
}

function flash_html(): string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return '';
    $cls = $f[0] === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200';
    return '<div class="mb-6 px-4 py-3 rounded-lg border text-sm ' . $cls . '">' . e($f[1]) . '</div>';
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Wajib dipanggil di setiap handler POST. */
function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Metode tidak diizinkan.');
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        flash('error', 'Sesi formulir kedaluwarsa. Silakan coba masuk kembali.');
        $back = $_SERVER['HTTP_REFERER'] ?? '../auth/login.php';
        redirect($back);
    }
}

/* ---------- Otorisasi ---------- */
/** Pastikan user login dengan peran tertentu ('penyewa' / 'mitra'). $base = path relatif ke root. */
function require_role(string $role, string $base = '../'): void
{
    $idKey = $role === 'mitra' ? 'id_mitra' : 'id_pengguna';
    if (($_SESSION['role'] ?? null) !== $role || empty($_SESSION[$idKey])) {
        flash('error', 'Silakan masuk terlebih dahulu.');
        redirect($base . 'auth/login.php');
    }
}

function int_param($v): ?int
{
    return filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
}