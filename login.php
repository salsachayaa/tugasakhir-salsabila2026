<?php
require_once 'includes/functions.php';
redirectIfLoggedIn();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = 'Email dan password harus diisi!';
    } else {
        $user = getUserByEmail($email);
        
        if (!$user) {
            $error = 'Email tidak terdaftar!';
        } elseif ($user['status'] !== 'ACTIVE') {
            $error = 'Akun Anda belum diaktivasi. Silakan cek email untuk tautan aktivasi.';
        } elseif (!verifyPassword($password, $user['password'])) {
            $error = 'Password salah!';
        } else {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['full_name'];
            
            logActivity($user['id'], 'LOGIN', 'Login ke sistem');
            
            header('Location: dashboard.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - User Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Custom Font */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #064e3b; }
        ::-webkit-scrollbar-thumb { background: #10b981; border-radius: 4px; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden bg-green-950">

    <!-- Gradient Background -->
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_#065f46_0%,_#022c22_100%)] z-0"></div>

    <!-- Animated Background Elements -->
    <div class="absolute top-0 left-0 w-full h-full overflow-hidden z-0 pointer-events-none">
        <div class="absolute top-10 left-10 w-96 h-96 bg-emerald-500 rounded-full mix-blend-overlay filter blur-3xl opacity-10 animate-pulse"></div>
        <div class="absolute bottom-10 right-10 w-96 h-96 bg-green-500 rounded-full mix-blend-overlay filter blur-3xl opacity-10 animate-pulse"></div>
    </div>

    <!-- Login Card -->
    <div class="bg-white/10 backdrop-blur-lg border border-white/20 rounded-2xl shadow-2xl p-8 max-w-md w-full relative z-10">
        
        <!-- Header -->
        <div class="flex items-center gap-4 mb-8 border-b border-green-400/30 pb-6">
            <div class="bg-white/90 p-2 rounded-xl shadow-lg border border-green-500">
                <img src="images/logo-skds.jpeg" alt="Logo PT. SKDS" class="w-14 h-14 object-contain rounded-lg">
            </div>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-wide">LOGIN MANAJAKEMEN LOGISTIK</h1>
                <p class="text-green-200 text-xs uppercase tracking-wider font-semibold">PT. Sarana Karya Dua Satu</p>
            </div>
        </div>

        <!-- Error Alert -->
        <?php if ($error): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-100 px-4 py-3 rounded-lg text-sm mb-6 flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Success Alert - Activated -->
        <?php if (isset($_GET['activated'])): ?>
            <div class="bg-green-500/20 border border-green-500/50 text-green-100 px-4 py-3 rounded-lg text-sm mb-6 flex items-center gap-2">
                <i class="fas fa-check-circle"></i> Akun berhasil diaktivasi! Silakan login.
            </div>
        <?php endif; ?>
        
        <!-- Success Alert - Reset Password -->
        <?php if (isset($_GET['reset'])): ?>
            <div class="bg-green-500/20 border border-green-500/50 text-green-100 px-4 py-3 rounded-lg text-sm mb-6 flex items-center gap-2">
                <i class="fas fa-check-circle"></i> Password berhasil direset! Silakan login dengan password baru.
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="">
            
            <!-- Email Input -->
            <div class="mb-5">
                <label for="email" class="block text-green-100 text-xs font-bold mb-2 uppercase tracking-wider">Email</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-green-300">
                        <i class="fas fa-envelope"></i>
                    </span>
                    <input type="email" id="email" name="email" required 
                           class="w-full pl-10 pr-4 py-3 bg-green-900/40 border border-green-600/50 rounded-lg focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400 text-white placeholder-green-200/40 transition"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           placeholder="Masukkan email Anda">
                </div>
            </div>
            
            <!-- Password Input -->
            <div class="mb-8">
                <label for="password" class="block text-green-100 text-xs font-bold mb-2 uppercase tracking-wider">Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-green-300">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" id="password" name="password" required
                           class="w-full pl-10 pr-4 py-3 bg-green-900/40 border border-green-600/50 rounded-lg focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400 text-white placeholder-green-200/40 transition"
                           placeholder="Masukkan password Anda">
                </div>
                <div class="text-right mt-2">
                    <a href="forgot_password.php" class="text-xs text-green-300 hover:text-white transition">Lupa Password?</a>
                </div>
            </div>
            
            <!-- Submit Button -->
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3 rounded-lg shadow-lg hover:shadow-emerald-500/30 transform hover:-translate-y-0.5 transition duration-200 border border-emerald-500">
                LOGIN <i class="fas fa-arrow-right ml-2"></i>
            </button>

        </form>

        <!-- Info Akun Baru -->
        <div class="mt-8 text-center pt-6 border-t border-white/10 text-sm text-green-200">
            Belum punya akun? Hubungi <span class="text-white font-bold">Pimpinan / Admin</span> untuk dibuatkan akun.
        </div>

    </div>

</body>
</html>