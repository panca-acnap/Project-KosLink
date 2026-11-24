<?php
require __DIR__ . '/../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tangkap tujuan redirect jika pengguna datang dari halaman detail kamar / booking
$redirect_to = '';
if (!empty($_GET['redirect'])) {
    $candidate = trim((string)$_GET['redirect']);
    // Cegah open-redirect ke domain eksternal
    if (strpos($candidate, 'http://') !== 0 && strpos($candidate, 'https://') !== 0 && strpos($candidate, '//') !== 0) {
        $redirect_to = ltrim($candidate, '/');
        $_SESSION['redirect_after_login'] = $redirect_to;
    }
} elseif (!empty($_SESSION['redirect_after_login'])) {
    $redirect_to = (string)$_SESSION['redirect_after_login'];
}

page_head('Login');
?>
<!-- Pastikan FontAwesome dimuat agar ikon mata muncul -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<body class="bg-gray-50 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md border">
        <div class="text-center mb-8">
            <a href="../index.php" class="text-2xl font-bold text-blue-600">KosLink</a>
            <p class="text-gray-500 mt-2">Masuk ke akun Anda</p>
        </div>

        <?= flash_html() ?>

        <?php if (!empty($_SESSION['error_login'])): ?>
            <div class="mb-4 p-3.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold flex items-start gap-2.5">
                <i class="fa-solid fa-circle-info text-amber-500 mt-0.5"></i>
                <span><?= htmlspecialchars($_SESSION['error_login']); unset($_SESSION['error_login']); ?></span>
            </div>
        <?php endif; ?>

        <form action="proses_login.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect_to) ?>">

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-gray-700">Email</label>
                <input type="email" name="email" class="w-full border rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1 text-gray-700">Password</label>
                <div class="relative">
                    <input type="password" name="password" id="password" class="w-full border rounded-lg p-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
                    <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none cursor-pointer">
                        <i id="eye-icon" class="fa-solid fa-eye text-base"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2.5 rounded-lg hover:bg-blue-700 transition">Masuk</button>
        </form>
        <p class="text-center text-sm text-gray-500 mt-6">Belum punya akun? <a href="register.php<?= $redirect_to !== '' ? '?redirect=' . urlencode($redirect_to) : '' ?>" class="text-blue-600 font-medium">Daftar</a></p>
        <p class="text-center text-sm text-gray-500 mt-3"><a href="#" class="text-blue-600 font-medium">Reset Password</a></p>
    </div>

    <!-- SCRIPT TOGGLE PASSWORD -->
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>