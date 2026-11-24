<?php
// Kerangka tampilan bersama (head, navbar publik, sidebar dasbor) agar tidak diulang di tiap halaman.

function page_head(string $title): void
{
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' - KosLink</title>'
       . '<script src="https://cdn.tailwindcss.com"></script></head>';
}

/** Navbar halaman publik (index, detail). $base = '' dari root. */
function public_nav(string $base = ''): void
{
    $role = $_SESSION['role'] ?? null; ?>
    <nav class="flex justify-between items-center px-8 py-4 bg-white border-b sticky top-0 z-50">
        <a href="<?= $base ?>index.php" class="text-blue-600 font-bold text-2xl">KosLink</a>
        <div class="hidden md:flex space-x-6 text-sm font-medium">
            <a href="<?= $base ?>index.php" class="text-blue-600">Beranda</a>
            <a href="<?= $base ?>index.php#hasil" class="text-gray-600 hover:text-blue-600">Cari Kos</a>
        </div>
        <div class="flex space-x-3">
            <?php if ($role): ?>
                <a href="<?= $base . ($role === 'mitra' ? 'mitra' : 'penyewa') ?>/dashboard.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Dasbor Saya</a>
                <a href="<?= $base ?>auth/logout.php" class="px-4 py-2 border text-red-600 rounded-lg text-sm font-medium">Keluar</a>
            <?php else: ?>
                <a href="<?= $base ?>auth/login.php" class="px-4 py-2 text-blue-600 border border-blue-600 rounded-lg text-sm font-medium">Masuk</a>
                <a href="<?= $base ?>auth/register.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Daftar</a>
            <?php endif; ?>
        </div>
    </nav>
<?php }

/** Buka halaman dasbor: <head>, sidebar, dan <main>. Tutup dengan dash_end(). */
function dash_start(string $title, string $role, string $active): void
{
    $menus = $role === 'mitra'
        ? ['dashboard' => ['Ringkasan', 'dashboard.php'], 'properti' => ['Properti Saya', 'properti.php'],
           'kamar' => ['Manajemen Kamar', 'kamar.php'], 'penyewa' => ['Data penyewa', 'penyewa.php'],
           'keluhan' => ['Keluhan Masuk', 'keluhan.php']]
        : ['dashboard' => ['Tagihan Saya', 'dashboard.php'], 'keluhan' => ['Komplain/Keluhan', 'keluhan.php']];
    page_head($title);
    echo '<body class="bg-gray-50 flex h-screen overflow-hidden"><aside class="w-64 bg-white border-r flex flex-col">'
       . '<div class="h-16 flex items-center px-6 border-b font-bold text-blue-600 text-xl">KosLink' . ($role === 'mitra' ? ' Mitra' : '') . '</div>'
       . '<nav class="flex-1 px-4 py-6 space-y-2 text-sm">';
    foreach ($menus as $key => [$label, $file]) {
        $cls = $key === $active ? 'bg-blue-50 text-blue-600 font-medium' : 'text-gray-600 hover:bg-gray-100';
        echo '<a href="' . $file . '" class="flex items-center px-4 py-2.5 rounded-lg ' . $cls . '">' . e($label) . '</a>';
    }
    if ($role === 'penyewa') {
        echo '<a href="../index.php" class="flex items-center px-4 py-2.5 text-gray-600 hover:bg-gray-100 rounded-lg">Cari Kos Lagi</a>';
    }
    echo '<a href="../auth/logout.php" class="flex items-center px-4 py-2.5 text-red-600 hover:bg-red-50 rounded-lg mt-8">Keluar</a>'
       . '</nav></aside><main class="flex-1 overflow-y-auto p-8">' . flash_html();
}

function dash_end(): void
{
    echo '</main></body></html>';
}
