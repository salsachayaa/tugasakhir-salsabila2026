<?php
require_once 'includes/functions.php';
redirectIfLoggedIn();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitizeInput($_POST['email']);
    
    if (empty($email)) {
        $error = 'Email harus diisi!';
    } elseif (!validateEmail($email)) {
        $error = 'Format email tidak valid!';
    } else {
        $user = getUserByEmail($email);
        
        if ($user && $user['status'] == 'ACTIVE') {
            // Generate reset token
            $reset_token = generateToken();
            
            // Set expiry 24 jam dari sekarang
            $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $conn = getDBConnection();
            
            // Hapus token lama dulu (jika ada)
            $stmt_clear = $conn->prepare("UPDATE users SET reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
            $stmt_clear->bind_param("i", $user['id']);
            $stmt_clear->execute();
            $stmt_clear->close();
            
            // Insert token baru
            $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
            $stmt->bind_param("ssi", $reset_token, $expiry, $user['id']);
            
            if ($stmt->execute()) {
                // Verifikasi token tersimpan
                $stmt_verify = $conn->prepare("SELECT reset_token, reset_token_expiry FROM users WHERE id = ?");
                $stmt_verify->bind_param("i", $user['id']);
                $stmt_verify->execute();
                $verify_result = $stmt_verify->get_result()->fetch_assoc();
                $stmt_verify->close();
                
                if ($verify_result && $verify_result['reset_token'] === $reset_token) {
                    // Send reset email
                    $reset_link = BASE_URL . "/reset_password.php?token=" . $reset_token;
                    $email_subject = "Reset Password - Dashboard Logistik";
                    $email_message = "
                        <html>
                        <body style='font-family: Arial, sans-serif;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f8f9fa; border-radius: 10px;'>
                                <h2 style='color: #10b981;'>Reset Password</h2>
                                <p>Halo, <strong>{$user['full_name']}</strong>!</p>
                                <p>Kami menerima permintaan untuk mereset password akun Anda.</p>
                                <p>Silakan klik tombol di bawah ini untuk membuat password baru:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='$reset_link' style='background: #10b981; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>Reset Password</a>
                                </div>
                                <p>Atau copy link berikut ke browser Anda:</p>
                                <p style='background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 5px; word-break: break-all;'>
                                    <a href='$reset_link'>$reset_link</a>
                                </p>
                                <p style='color: #e65100; font-weight: bold;'>⚠️ Link ini akan berlaku selama 24 jam.</p>
                                <p style='color: #666; font-size: 12px;'>Waktu kedaluwarsa: {$expiry}</p>
                                <hr style='border: 1px solid #ddd; margin: 20px 0;'>
                                <p style='color: #666; font-size: 13px;'>Jika Anda tidak meminta reset password, abaikan email ini dan password Anda akan tetap aman.</p>
                                <p style='color: #666; font-size: 13px;'>Salam,<br><strong>Tim Dashboard Logistik</strong></p>
                            </div>
                        </body>
                        </html>
                    ";
                    
                    if (sendEmail($email, $email_subject, $email_message)) {
                        $success = 'Link reset password telah dikirim ke email Anda. Silakan cek inbox atau folder spam. Link berlaku selama 24 jam.';
                    } else {
                        // Jika email gagal, tampilkan link langsung untuk testing
                        $success = 'Link reset password berhasil dibuat!||' . $reset_link . '||' . $reset_token . '||' . $expiry;
                    }
                } else {
                    $error = 'Token gagal tersimpan. Silakan coba lagi.';
                }
            } else {
                $error = 'Terjadi kesalahan. Silakan coba lagi.';
            }
            
            $stmt->close();
            $conn->close();
        } else {
            // Untuk keamanan, tampilkan pesan yang sama meskipun email tidak ditemukan
            $success = 'Jika email terdaftar, link reset password telah dikirim ke email Anda.';
        }
    }
}

// Parse success message
$success_parts = explode('||', $success);
$success_message = $success_parts[0];
$reset_link = isset($success_parts[1]) ? $success_parts[1] : '';
$reset_token = isset($success_parts[2]) ? $success_parts[2] : '';
$expiry_time = isset($success_parts[3]) ? $success_parts[3] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - Dashboard Logistik</title>
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
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .animate-pulse-subtle {
            animation: pulse 2s ease-in-out infinite;
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
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-full mb-4 animate-pulse-subtle">
                    <i class="fas fa-key text-white text-2xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Lupa Password?</h2>
                <p class="text-green-200 text-sm">Jangan khawatir, kami akan membantu Anda</p>
            </div>

            <?php if ($error): ?>
            <!-- Error Alert -->
            <div class="bg-red-500/20 backdrop-blur border border-red-500/50 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-exclamation-circle text-red-400 text-xl mt-0.5"></i>
                    <div class="flex-1">
                        <h4 class="text-white font-bold mb-1">Perhatian!</h4>
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
                        <h4 class="text-white font-bold mb-2">Email Terkirim!</h4>
                        <p class="text-emerald-100 text-sm leading-relaxed">
                            <?php echo $success_message; ?>
                        </p>
                    </div>
                </div>
            </div>

            <?php if ($reset_link): ?>
            <!-- Testing Link Display -->
            <div class="bg-orange-500/20 backdrop-blur border border-orange-500/50 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-orange-400 text-xl mt-0.5"></i>
                    <div class="flex-1">
                        <h4 class="text-white font-bold mb-2">⚠️ Mode Testing</h4>
                        <p class="text-orange-100 text-xs mb-3">Email tidak terkirim. Gunakan link berikut:</p>
                        <a href="<?php echo $reset_link; ?>" class="block bg-white/10 border border-white/20 rounded-lg p-3 mb-3 hover:bg-white/20 transition break-all">
                            <p class="text-emerald-300 text-xs font-mono"><?php echo $reset_link; ?></p>
                        </a>
                        <div class="space-y-1">
                            <p class="text-orange-100 text-xs"><strong>Token:</strong> <code class="bg-white/10 px-2 py-1 rounded"><?php echo $reset_token; ?></code></p>
                            <p class="text-orange-100 text-xs"><strong>Expired:</strong> <?php echo $expiry_time; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Success Actions -->
            <div class="space-y-3">
                <a href="login.php" class="block w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-center px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Login
                </a>
            </div>
            
            <?php else: ?>
            <!-- Info Box -->
            <div class="bg-blue-500/20 backdrop-blur border border-blue-500/30 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-blue-400 text-lg mt-0.5"></i>
                    <p class="text-blue-100 text-sm leading-relaxed">
                        Masukkan alamat email Anda yang terdaftar. Kami akan mengirimkan link untuk mereset password Anda.
                    </p>
                </div>
            </div>
            
            <!-- Form -->
            <form method="POST" action="" class="space-y-6">
                <div>
                    <label for="email" class="block text-green-200 text-sm font-semibold mb-2">
                        <i class="fas fa-envelope mr-2"></i>Email
                    </label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           placeholder="nama@email.com"
                           class="w-full bg-white/10 border border-white/20 rounded-lg px-4 py-3 text-white placeholder-green-300/50 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/50 transition">
                </div>
                
                <button type="submit" class="w-full bg-gradient-to-r from-emerald-600 to-emerald-700 text-white px-6 py-3 rounded-xl font-bold hover:from-emerald-700 hover:to-emerald-800 transition transform hover:scale-105 shadow-lg">
                    <i class="fas fa-paper-plane mr-2"></i>Kirim Link Reset Password
                </button>
            </form>

            <!-- Back to Login -->
            <div class="mt-6">
                <a href="login.php" class="block w-full bg-white/10 backdrop-blur border border-white/20 text-white text-center px-6 py-3 rounded-xl font-bold hover:bg-white/20 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Login
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Help Section -->
        <div class="bg-white/5 backdrop-blur border border-white/10 rounded-xl p-4 text-center mt-6 animate-fade-in-up" style="animation-delay: 0.4s;">
            <p class="text-green-200 text-sm mb-2">
                <i class="fas fa-question-circle mr-2"></i>Butuh bantuan?
            </p>
            <a href="mailto:support@ptskds.com" class="text-emerald-400 hover:text-emerald-300 text-sm font-semibold transition">
                Hubungi Support
            </a>
        </div>

        <!-- Additional Help -->
        <div class="mt-6 text-center animate-fade-in-up" style="animation-delay: 0.5s;">
            <div class="bg-white/5 backdrop-blur border border-white/10 rounded-xl p-4">
                <h4 class="text-white font-bold text-sm mb-3">💡 Tips Keamanan</h4>
                <ul class="text-green-200 text-xs space-y-2 text-left">
                    <li class="flex items-start gap-2">
                        <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                        <span>Link reset password hanya berlaku selama 24 jam</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                        <span>Jangan bagikan link reset kepada siapapun</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                        <span>Gunakan password yang kuat dan unik</span>
                    </li>
                </ul>
            </div>
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