<?php
require __DIR__ . '/../koneksi.php';
page_head('Daftar');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<body class="bg-gray-50 flex items-center justify-center min-h-screen py-8">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md border">
        <div class="text-center mb-6">
            <a href="../index.php" class="text-2xl font-bold text-blue-600">KosLink</a>
            <p class="text-gray-500 mt-2">Buat akun baru</p>
        </div>
        <?= flash_html() ?>
        <form action="proses_register.php" method="POST">
            <?= csrf_field() ?>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-gray-700">Nama Lengkap</label>
                <input type="text" name="nama" maxlength="100" class="w-full border rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-gray-700">Email</label>
                <input type="email" name="email" maxlength="100" class="w-full border rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-gray-700">No. HP</label>
                <input type="text" name="no_hp" maxlength="20" class="w-full border rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
            </div>

            <!-- FIELD KHUSUS ADMIN (Sesuai kolom database: id_mitra, id_properti, nama_admin, email, no_hp, status_akun) -->
            <div id="admin-extra-fields" class="mb-4 p-4 bg-blue-50/60 border border-blue-200 rounded-xl space-y-3 hidden">
                <p class="text-xs font-bold text-blue-700 uppercase tracking-wider">
                    <i class="fa-solid fa-shield-halved mr-1"></i> Informasi Penugasan Admin
                </p>
                
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-700">Email Pemilik / Mitra Penanggung Jawab <span class="text-rose-500">*</span></label>
                    <input type="email" name="email_mitra" id="input-email-mitra" placeholder="Contoh: pemilik@koslink.com" class="w-full bg-white border border-slate-200 rounded-lg p-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-600">
                    <p class="text-[10px] text-slate-400 mt-0.5">Sistem mencocokkan ID Mitra secara otomatis.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-700">Nama Cabang / Properti yang Dikelola</label>
                    <input type="text" name="nama_properti" placeholder="Contoh: Kos Melati (Opsional)" class="w-full bg-white border border-slate-200 rounded-lg p-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-600">
                    <p class="text-[10px] text-slate-400 mt-0.5">Kosongkan jika mengelola seluruh properti.</p>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-gray-700">Password (min. 6 karakter)</label>
                <div class="relative">
                    <input type="password" name="password" id="password" minlength="6" class="w-full border rounded-lg p-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
                    <button type="button" onclick="togglePass('password', 'eye-1')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                        <i id="eye-1" class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1 text-gray-700">Ulangi Password</label>
                <div class="relative">
                    <input type="password" name="password2" id="password2" minlength="6" class="w-full border rounded-lg p-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
                    <button type="button" onclick="togglePass('password2', 'eye-2')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                        <i id="eye-2" class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2.5 rounded-lg hover:bg-blue-700 transition">Daftar Akun</button>
        </form>
        <p class="text-center text-sm text-gray-500 mt-6">Sudah punya akun? <a href="login.php" class="text-blue-600 font-medium">Masuk</a></p>
    </div>

    <script>
        function toggleAdminFields() {
            const roleSelect = document.getElementById('role-select');
            const adminFields = document.getElementById('admin-extra-fields');
            const emailMitraInput = document.getElementById('input-email-mitra');

            if (roleSelect.value === 'admin') {
                adminFields.classList.remove('hidden');
                emailMitraInput.setAttribute('required', 'required');
            } else {
                adminFields.classList.add('hidden');
                emailMitraInput.removeAttribute('required');
            }
        }

        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>