<?php
require_once 'includes/functions.php';

$message = '';
$success = false;

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = sanitizeInput($_GET['token']);
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE activation_token = ? AND status = 'PENDING'");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        // Activate account
        $stmt2 = $conn->prepare("UPDATE users SET status = 'ACTIVE', activation_token = NULL WHERE id = ?");
        $stmt2->bind_param("i", $user['id']);
        
        if ($stmt2->execute()) {
            $success = true;
            $message = 'Akun Anda berhasil diaktivasi! Silakan login untuk melanjutkan.';
        } else {
            $message = 'Terjadi kesalahan saat mengaktivasi akun. Silakan coba lagi.';
        }
        $stmt2->close();
    } else {
        $message = 'Token aktivasi tidak valid atau akun sudah diaktivasi.';
    }
    
    $stmt->close();
    $conn->close();
} else {
    $message = 'Token aktivasi tidak ditemukan.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivasi Akun - Dashboard Logistik</title>
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
        @keyframes checkmark {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.2);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        .checkmark {
            animation: checkmark 0.6s ease-out 0.3s both;
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

        <!-- Activation Card -->
        <div class="bg-white/10 backdrop-blur-lg border border-white/20 rounded-2xl p-8 shadow-2xl animate-fade-in-up" style="animation-delay: 0.2s;">
            <div class="text-center mb-6">
                <?php if ($success): ?>
                <!-- Success Icon -->
                <div class="inline-flex items-center justify-center w-24 h-24 bg-emerald-600 rounded-full mb-4">
                    <i class="fas fa-check text-white text-5xl checkmark"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Aktivasi Berhasil!</h2>
                <?php else: ?>
                <!-- Error Icon -->
                <div class="inline-flex items-center justify-center w-24 h-24 bg-red-600 rounded-full mb-4 shake">
                    <i class="fas fa-times text-white text-5xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Aktivasi Gagal</h2>
                <?php endif; ?>
            </div>

            <!-- Message Box -->
            <div class="<?php echo $success ? 'bg-emerald-500/20 border-emerald-500/50' : 'bg-red-500/20 border-red-500/50'; ?> border backdrop-blur rounded-xl p-6 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-<?php echo $success ? 'check-circle text-emerald-400' : 'exclamation-circle text-red-400'; ?> text-2xl mt-1"></i>
                    <div>
                        <h3 class="text-white font-bold mb-2">
                            <?php echo $success ? '✅ Sukses' : '⚠️ Perhatian'; ?>
                        </h3>
                        <p class="<?php echo $success ? 'text-emerald-100' : 'text-red-100'; ?> text-sm leading-relaxed">
                            <?php echo $message; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="space-y-3">
                <?php if ($success): ?>
                <a href="login.php" class="block w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-center px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-sign-in-alt mr-2"></i>Login Sekarang
                </a>
                <?php else: ?>
                <a href="register.php" class="block w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-center px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-user-plus mr-2"></i>Coba Registrasi Lagi
                </a>
                <?php endif; ?>
                
                <a href="login.php" class="block w-full bg-white/10 backdrop-blur border border-white/20 text-white text-center px-6 py-3 rounded-xl font-bold hover:bg-white/20 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Login
                </a>
            </div>
        </div>

        <!-- Help Section -->
        <div class="bg-white/5 backdrop-blur border border-white/10 rounded-xl p-4 text-center animate-fade-in-up" style="animation-delay: 0.4s;">
            <p class="text-green-200 text-sm mb-2">
                <i class="fas fa-question-circle mr-2"></i>Butuh bantuan?
            </p>
            <a href="mailto:support@ptskds.com" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold transition">
                Hubungi Support
            </a>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 animate-fade-in-up" style="animation-delay: 0.6s;">
            <p class="text-green-300 text-xs">
                &copy; <?php echo date('Y'); ?> PT. Sarana Karya Dua Satu. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>