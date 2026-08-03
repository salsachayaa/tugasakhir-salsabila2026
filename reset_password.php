<?php
require_once 'includes/functions.php';
redirectIfLoggedIn();

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$user = null;

if (!empty($token)) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() AND status = 'ACTIVE'");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    if ($user) {
        $valid_token = true;
    } else {
        $error = 'Link reset password tidak valid atau sudah kadaluarsa.';
    }
} else {
    $error = 'Token tidak ditemukan.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || empty($confirm_password)) {
        $error = 'Semua field harus diisi!';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter!';
    } elseif ($password !== $confirm_password) {
        $error = 'Password dan konfirmasi password tidak cocok!';
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $user['id']);
        
        if ($stmt->execute()) {
            $success = 'Password berhasil diubah! Silakan login dengan password baru Anda.';
            $valid_token = false;
        } else {
            $error = 'Terjadi kesalahan. Silakan coba lagi.';
        }
        
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Dashboard Logistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        .shake {
            animation: shake 0.5s ease-out;
        }
    </style>
</head>
<body class="min-h-screen bg-green-950 flex items-center justify-center p-4">
    <!-- Background Effects -->
    <div class="fixed inset-0 bg-[radial-gradient(ellipse_at_center,_#065f46_0%,_#022c22_100%)] z-0"></div>
    <div class="fixed top-0 left-0 w-full h-full overflow-hidden z-0 pointer-events-none">
        <div class="absolute top-10 left-10 w-96 h-96 bg-emerald-500 rounded-full mix-blend-overlay filter blur-3xl opacity-10 animate-pulse"></div>
        <div class="absolute bottom-10 right-10 w-96 h-96 bg-green-500 rounded-full mix-blend-overlay filter blur-3xl opacity-10 animate-pulse"></div>
    </div>

    <!-- Main Container -->
    <div class="relative z-10 w-full max-w-md">
        <!-- Logo Section -->
        <div class="text-center mb-8 animate-fade-in-up">
            <div class="inline-block bg-white/90 p-4 rounded-2xl shadow-2xl mb-4">
                <img src="images/logo-skds.jpeg" alt="Logo PT. SKDS" class="w-20 h-20 object-contain">
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Dashboard Logistik</h1>
            <p class="text-green-200 text-sm">PT. Sarana Karya Dua Satu</p>
        </div>

        <!-- Main Card -->
        <div class="bg-white/10 backdrop-blur-lg border border-white/20 rounded-2xl p-8 shadow-2xl animate-fade-in-up" style="animation-delay: 0.2s;">
            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center gap-4 mb-4">
                    <div class="bg-gradient-to-br from-emerald-600 to-emerald-700 p-3 rounded-xl">
                        <i class="fas fa-lock text-white text-2xl"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white">Reset Password</h2>
                        <p class="text-green-200 text-sm">Buat password baru Anda</p>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
            <!-- Error Alert -->
            <div class="bg-red-500/20 backdrop-blur border border-red-500/50 rounded-xl p-4 mb-6 shake">
                <div class="flex items-start gap-3">
                    <i class="fas fa-exclamation-circle text-red-400 text-xl mt-0.5"></i>
                    <div class="flex-1">
                        <h4 class="text-white font-bold mb-1">Error!</h4>
                        <p class="text-red-100 text-sm"><?php echo $error; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <!-- Success Alert -->
            <div class="bg-emerald-500/20 backdrop-blur border border-emerald-500/50 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-check-circle text-emerald-400 text-xl mt-0.5"></i>
                    <div class="flex-1">
                        <h4 class="text-white font-bold mb-2">Berhasil!</h4>
                        <p class="text-emerald-100 text-sm leading-relaxed">
                            <?php echo $success; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Success Actions -->
            <div class="space-y-3">
                <a href="login.php" class="block w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-center px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-sign-in-alt mr-2"></i>Login Sekarang
                </a>
            </div>
            
            <?php elseif ($valid_token): ?>
            <!-- Info Box -->
            <div class="bg-emerald-500/20 backdrop-blur border border-emerald-500/30 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-emerald-400 text-lg mt-0.5"></i>
                    <p class="text-emerald-100 text-sm leading-relaxed">
                        <strong>Info:</strong> Masukkan password baru Anda. Password minimal 6 karakter.
                    </p>
                </div>
            </div>
            
            <!-- Form -->
            <form method="POST" action="" class="space-y-5">
                <div>
                    <label for="password" class="block text-green-200 text-sm font-semibold mb-2">
                        <i class="fas fa-key mr-2"></i>Password Baru *
                    </label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Minimal 6 karakter"
                           class="w-full bg-white/10 border border-white/20 rounded-lg px-4 py-3 text-white placeholder-green-300/50 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/50 transition">
                    <p class="text-green-300 text-xs mt-1">Minimal 6 karakter</p>
                </div>
                
                <div>
                    <label for="confirm_password" class="block text-green-200 text-sm font-semibold mb-2">
                        <i class="fas fa-lock mr-2"></i>Konfirmasi Password Baru *
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" required 
                           placeholder="Ulangi password baru"
                           class="w-full bg-white/10 border border-white/20 rounded-lg px-4 py-3 text-white placeholder-green-300/50 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/50 transition">
                </div>
                
                <button type="submit" class="w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-check mr-2"></i>Reset Password
                </button>
            </form>

            <!-- Back to Login -->
            <div class="mt-4">
                <a href="login.php" class="block w-full bg-white/10 backdrop-blur border border-white/20 text-white text-center px-6 py-3 rounded-xl font-bold hover:bg-white/20 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Login
                </a>
            </div>
            
            <?php else: ?>
            <!-- Invalid Token -->
            <div class="text-center space-y-4">
                <a href="login.php" class="block w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-center px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Login
                </a>
                <a href="forgot_password.php" class="block w-full bg-white/10 backdrop-blur border border-white/20 text-white text-center px-6 py-3 rounded-xl font-bold hover:bg-white/20 transition">
                    <i class="fas fa-redo mr-2"></i>Minta Link Baru
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Security Tips -->
        <div class="mt-6 animate-fade-in-up" style="animation-delay: 0.4s;">
            <div class="bg-white/5 backdrop-blur border border-white/10 rounded-xl p-4">
                <h4 class="text-white font-bold text-sm mb-3 flex items-center gap-2">
                    <i class="fas fa-shield-alt text-emerald-400"></i>
                    Tips Password Aman
                </h4>
                <ul class="text-green-200 text-xs space-y-2">
                    <li class="flex items-start gap-2">
                        <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                        <span>Gunakan kombinasi huruf besar, kecil, angka</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                        <span>Minimal 6 karakter, lebih panjang lebih baik</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                        <span>Jangan gunakan password yang sama di aplikasi lain</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Help Section -->
        <div class="bg-white/5 backdrop-blur border border-white/10 rounded-xl p-4 text-center mt-4 animate-fade-in-up" style="animation-delay: 0.5s;">
            <p class="text-green-200 text-sm mb-2">
                <i class="fas fa-question-circle mr-2"></i>Butuh bantuan?
            </p>
            <a href="mailto:support@ptskds.com" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold transition">
                Hubungi Support
            </a>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 animate-fade-in-up" style="animation-delay: 0.6s;">
            <p class="text-green-300 text-xs">
                &copy; <?php echo date('Y'); ?> PT. Sarana Karya Dua Satu. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>