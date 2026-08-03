<?php
require_once 'includes/functions.php';
redirectIfLoggedIn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi Ditutup - Sistem Manajemen Logistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-green-950 flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-[radial-gradient(ellipse_at_center,_#065f46_0%,_#022c22_100%)] z-0"></div>

    <div class="relative z-10 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-block bg-white/90 p-4 rounded-2xl shadow-2xl mb-4">
                <img src="images/logo-skds.jpeg" alt="Logo PT. SKDS" class="w-20 h-20 object-contain">
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Dashboard Logistik</h1>
            <p class="text-green-200 text-sm">PT. Sarana Karya Dua Satu</p>
        </div>

        <div class="bg-white/10 backdrop-blur-lg border border-white/20 rounded-2xl p-8 shadow-2xl">
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center w-24 h-24 bg-amber-500/90 rounded-full mb-4">
                    <i class="fas fa-user-lock text-white text-4xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Pendaftaran Mandiri Ditutup</h2>
            </div>

            <div class="bg-amber-500/10 border border-amber-500/30 backdrop-blur rounded-xl p-6 mb-6">
                <p class="text-amber-100 text-sm leading-relaxed text-center">
                    Untuk keamanan, akun baru tidak bisa dibuat sendiri di halaman ini.
                    Jika Anda karyawan baru, silakan hubungi <strong>Pimpinan / Admin</strong>
                    untuk dibuatkan akun. Kredensial login akan dikirimkan ke email Anda.
                </p>
            </div>

            <a href="login.php" class="block w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-center px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                <i class="fas fa-arrow-left mr-2"></i>Kembali ke Login
            </a>
        </div>

        <div class="text-center mt-8">
            <p class="text-green-300 text-xs">
                &copy; <?php echo date('Y'); ?> PT. Sarana Karya Dua Satu. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>