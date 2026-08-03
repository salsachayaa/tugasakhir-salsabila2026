<?php
require_once __DIR__ . '/role_functions.php';
// ...isi functions.php yang lain tetap di bawahnya seperti biasa

// Load database configuration PERTAMA sebelum yang lain
require_once __DIR__ . '/../config/database.php';

// Baru load autoload dan library
require __DIR__ . '/../vendor/autoload.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Generate random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Send email function (gunakan PHPMailer)
function sendEmail($to, $subject, $message) {
    $mail = new PHPMailer(true);
    try {
        // Konfigurasi SMTP
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Opsi tambahan untuk Gmail (hindari SSL error)
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Info pengirim & penerima
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        // Isi email
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $message;

        // Kirim email
        $mail->send();
        
        // Log sukses
        $logFile = __DIR__ . '/../email_log.txt';
        $successMessage = "=== Email SUCCESS at " . date('Y-m-d H:i:s') . " ===\n";
        $successMessage .= "To: $to\n";
        $successMessage .= "Subject: $subject\n";
        $successMessage .= "==========================================\n\n";
        file_put_contents($logFile, $successMessage, FILE_APPEND);
        
        return true;
    } catch (Exception $e) {
        // Simpan ke log jika gagal
        $logFile = __DIR__ . '/../email_log.txt';
        $errorMessage = "=== Email FAILED at " . date('Y-m-d H:i:s') . " ===\n";
        $errorMessage .= "To: $to\n";
        $errorMessage .= "Subject: $subject\n";
        $errorMessage .= "Error: {$mail->ErrorInfo}\n";
        $errorMessage .= "Exception: {$e->getMessage()}\n";
        $errorMessage .= "==========================================\n\n";
        file_put_contents($logFile, $errorMessage, FILE_APPEND);
        return false;
    }
}

// Kirim email aktivasi
function sendActivationEmail($user, $token) {
    $activationLink = BASE_URL . '/activate.php?token=' . $token;
    
    $emailBody = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                <h2 style='color: #4CAF50;'>Selamat Datang, {$user['name']}!</h2>
                <p>Terima kasih telah mendaftar sebagai <strong>{$user['role']}</strong>.</p>
                <p>Silakan klik tombol di bawah ini untuk mengaktifkan akun Anda:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$activationLink' 
                       style='background-color: #4CAF50; color: white; padding: 12px 30px; 
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Aktifkan Akun
                    </a>
                </div>
                <p>Atau copy link berikut ke browser Anda:</p>
                <p style='background-color: #f4f4f4; padding: 10px; border-radius: 3px; word-break: break-all;'>
                    <a href='$activationLink'>$activationLink</a>
                </p>
                <p style='color: #666; font-size: 14px;'>
                    <strong>Catatan:</strong> Link aktivasi ini akan berlaku selama 24 jam.
                </p>
                <p style='color: #999; font-size: 12px; margin-top: 30px;'>
                    Jika Anda tidak merasa mendaftar, abaikan email ini.
                </p>
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                <p style='color: #666; font-size: 14px;'>
                    Salam,<br>
                    <strong>Tim User Management System</strong><br>
                    PT. Sarana Karya Dua Satu
                </p>
            </div>
        </body>
        </html>
    ";
    
    return sendEmail($user['email'], 'Aktivasi Akun - User Management System', $emailBody);
}

// Kirim ulang email aktivasi
function resendActivationEmail($email) {
    $user = getUserByEmail($email);
    
    if (!$user) {
        return ['success' => false, 'message' => 'Email tidak ditemukan.'];
    }
    
    if ($user['status'] === 'ACTIVE') {
        return ['success' => false, 'message' => 'Akun sudah aktif. Silakan login.'];
    }
    
    // Generate token baru jika sudah tidak ada
    if (empty($user['activation_token'])) {
        $token = generateToken();
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET activation_token = ? WHERE id = ?");
        $stmt->bind_param("si", $token, $user['id']);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } else {
        $token = $user['activation_token'];
    }
    
    // Kirim email
    $result = sendActivationEmail($user, $token);
    
    if ($result) {
        return ['success' => true, 'message' => 'Email aktivasi telah dikirim ulang. Silakan cek inbox atau folder spam Anda.'];
    } else {
        return ['success' => false, 'message' => 'Gagal mengirim email. Silakan coba lagi nanti.'];
    }
}

// Kirim email reset password
function sendPasswordResetEmail($user, $token) {
    $resetLink = BASE_URL . '/reset-password.php?token=' . $token;
    
    $emailBody = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                <h2 style='color: #2196F3;'>Reset Password</h2>
                <p>Halo <strong>{$user['name']}</strong>,</p>
                <p>Kami menerima permintaan untuk mereset password akun Anda.</p>
                <p>Silakan klik tombol di bawah ini untuk mereset password:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$resetLink' 
                       style='background-color: #2196F3; color: white; padding: 12px 30px; 
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Reset Password
                    </a>
                </div>
                <p>Atau copy link berikut ke browser Anda:</p>
                <p style='background-color: #f4f4f4; padding: 10px; border-radius: 3px; word-break: break-all;'>
                    <a href='$resetLink'>$resetLink</a>
                </p>
                <p style='color: #666; font-size: 14px;'>
                    <strong>Catatan:</strong> Link ini akan berlaku selama 1 jam.
                </p>
                <p style='color: #999; font-size: 12px; margin-top: 30px;'>
                    Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak akan berubah.
                </p>
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                <p style='color: #666; font-size: 14px;'>
                    Salam,<br>
                    <strong>Tim User Management System</strong><br>
                    PT. Sarana Karya Dua Satu
                </p>
            </div>
        </body>
        </html>
    ";
    
    return sendEmail($user['email'], 'Reset Password - User Management System', $emailBody);
}

// Cek apakah user sudah login
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

// Redirect jika belum login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Redirect jika sudah login
function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        header('Location: dashboard.php');
        exit();
    }
}

// Cek role user
function hasRole($allowedRoles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getUserById($_SESSION['user_id']);
    if (!$user) {
        return false;
    }
    
    if (is_array($allowedRoles)) {
        return in_array($user['role'], $allowedRoles);
    }
    
    return $user['role'] === $allowedRoles;
}

// Require specific role
function requireRole($allowedRoles) {
    if (!hasRole($allowedRoles)) {
        header('Location: dashboard.php');
        exit();
    }
}

// Sanitasi input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Validasi email
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Validasi password strength
function validatePassword($password) {
    // Minimal 8 karakter, ada huruf besar, huruf kecil, dan angka
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => 'Password minimal 8 karakter.'];
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => 'Password harus mengandung huruf besar.'];
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'message' => 'Password harus mengandung huruf kecil.'];
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password harus mengandung angka.'];
    }
    
    return ['valid' => true, 'message' => 'Password valid.'];
}

// Hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Verifikasi password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Ambil user berdasarkan email
function getUserByEmail($email) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $user;
}

// Ambil user berdasarkan ID
function getUserById($id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $user;
}

// Ambil semua users
function getAllUsers() {
    $conn = getDBConnection();
    $result = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $conn->close();
    return $users;
}

// Buat user baru langsung oleh admin/pimpinan (tanpa proses registrasi mandiri)
// Akun langsung berstatus ACTIVE, tidak perlu aktivasi email.
function createUserByAdmin($fullName, $email, $phone, $password, $role) {
    $allowedRoles = ['admin', 'pimpinan', 'staff'];

    if (empty($fullName) || empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Nama, email, dan password wajib diisi.'];
    }

    if (!validateEmail($email)) {
        return ['success' => false, 'message' => 'Format email tidak valid.'];
    }

    if (!in_array($role, $allowedRoles, true)) {
        return ['success' => false, 'message' => 'Role tidak valid.'];
    }

    if (getUserByEmail($email)) {
        return ['success' => false, 'message' => 'Email sudah terdaftar.'];
    }

    $hashedPassword = hashPassword($password);

    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, phone, status, role) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $stmt->bind_param("sssss", $email, $hashedPassword, $fullName, $phone, $role);
    $success = $stmt->execute();
    $newUserId = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Gagal menyimpan pengguna ke database.'];
    }

    return ['success' => true, 'message' => 'Pengguna berhasil dibuat.', 'user_id' => $newUserId];
}

// Kirim email berisi kredensial akun baru yang dibuat oleh admin/pimpinan
function sendNewAccountEmail($user, $plainPassword) {
    $loginLink = BASE_URL . '/login.php';

    $emailBody = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                <h2 style='color: #4CAF50;'>Selamat Datang, {$user['full_name']}!</h2>
                <p>Akun Anda telah dibuat oleh admin/pimpinan pada Sistem Manajemen Logistik.</p>
                <p>Berikut kredensial login Anda:</p>
                <table style='background-color: #f4f4f4; padding: 10px; border-radius: 5px; width: 100%;'>
                    <tr><td style='padding: 6px;'><strong>Email</strong></td><td style='padding: 6px;'>{$user['email']}</td></tr>
                    <tr><td style='padding: 6px;'><strong>Password</strong></td><td style='padding: 6px;'>{$plainPassword}</td></tr>
                    <tr><td style='padding: 6px;'><strong>Role</strong></td><td style='padding: 6px;'>{$user['role']}</td></tr>
                </table>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$loginLink' 
                       style='background-color: #4CAF50; color: white; padding: 12px 30px; 
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Login Sekarang
                    </a>
                </div>
                <p style='color: #666; font-size: 14px;'>
                    <strong>Catatan:</strong> Segera ganti password Anda setelah login pertama kali.
                </p>
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                <p style='color: #666; font-size: 14px;'>
                    Salam,<br>
                    <strong>Tim User Management System</strong><br>
                    PT. Sarana Karya Dua Satu
                </p>
            </div>
        </body>
        </html>
    ";

    return sendEmail($user['email'], 'Akun Anda Telah Dibuat - User Management System', $emailBody);
}

// Update role user
function updateUserRole($userId, $role) {
    $allowedRoles = ['admin', 'pimpinan', 'staff'];
    if (!in_array($role, $allowedRoles, true)) {
        return false;
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->bind_param("si", $role, $userId);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

// Update user status
function updateUserStatus($userId, $status) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $userId);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

// Delete user
function deleteUser($userId) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

// Format mata uang
function formatCurrency($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Format tanggal Indonesia
function formatDate($date) {
    return date('d/m/Y H:i', strtotime($date));
}

// Format tanggal Indonesia lengkap
function formatDateLong($date) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = $bulan[date('n', $timestamp)];
    $year = date('Y', $timestamp);
    $time = date('H:i', $timestamp);
    
    return "$day $month $year, $time";
}

// Time ago format
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Baru saja';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' menit yang lalu';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' jam yang lalu';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' hari yang lalu';
    } else {
        return formatDate($datetime);
    }
}

// Generate random string
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// Escape output untuk mencegah XSS
function escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Redirect helper
function redirect($url) {
    header("Location: $url");
    exit();
}

// Flash message helper
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}

// Log activity
function logActivity($userId, $action, $description = '') {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt->bind_param("issss", $userId, $action, $description, $ipAddress, $userAgent);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();

        return $result;
    } catch (Throwable $e) {
        // Jangan sampai kegagalan logging menghentikan proses utama (misal: pembuatan akun).
        $logFile = __DIR__ . '/../email_log.txt';
        file_put_contents(
            $logFile,
            "=== logActivity FAILED at " . date('Y-m-d H:i:s') . " ===\nError: {$e->getMessage()}\n==========================================\n\n",
            FILE_APPEND
        );
        return false;
    }
}

// ========================================
// FITUR: NOTIFIKASI BARANG KADALUARSA
// ========================================

// Ambil status kadaluarsa sebuah item stock berdasarkan expiry_date & sisa qty
// Return: null jika tidak ada expiry_date atau qty sudah habis, atau array status
function getExpiryStatus($expiry_date, $current_quantity, $warningDays = 30) {
    if (empty($expiry_date) || $current_quantity <= 0) {
        return null;
    }

    $today = new DateTime(date('Y-m-d'));
    $expiry = new DateTime($expiry_date);
    $diff = (int) $today->diff($expiry)->format('%r%a'); // negatif jika sudah lewat

    if ($diff < 0) {
        return ['status' => 'EXPIRED', 'label' => 'KADALUARSA', 'class' => 'badge-kritis', 'days' => $diff];
    } elseif ($diff <= $warningDays) {
        return ['status' => 'WARNING', 'label' => 'H-' . $diff, 'class' => 'badge-sedang', 'days' => $diff];
    }

    return ['status' => 'SAFE', 'label' => 'AMAN', 'class' => 'badge-aman', 'days' => $diff];
}

// Ambil semua item stock yang sudah kadaluarsa atau akan kadaluarsa (H-warningDays)
// dan masih punya sisa stock (current_quantity > 0)
function getExpiringStockItems($warningDays = 30) {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT * FROM inventory_stock 
         WHERE expiry_date IS NOT NULL 
           AND current_quantity > 0 
           AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY expiry_date ASC"
    );
    $stmt->bind_param("i", $warningDays);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['expiry_info'] = getExpiryStatus($row['expiry_date'], $row['current_quantity'], $warningDays);
        $items[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $items;
}

// Ringkasan jumlah untuk badge / dashboard card
function getExpiryNotificationSummary($warningDays = 30) {
    $items = getExpiringStockItems($warningDays);
    $summary = ['expired' => 0, 'warning' => 0, 'total' => count($items)];
    foreach ($items as $item) {
        if ($item['expiry_info']['status'] === 'EXPIRED') {
            $summary['expired']++;
        } elseif ($item['expiry_info']['status'] === 'WARNING') {
            $summary['warning']++;
        }
    }
    return $summary;
}

// Jumlah total untuk badge sidebar (dipanggil di tiap halaman)
function getExpiryAlertCount($warningDays = 30) {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as total FROM inventory_stock 
         WHERE expiry_date IS NOT NULL 
           AND current_quantity > 0 
           AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)"
    );
    $stmt->bind_param("i", $warningDays);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    $conn->close();
    return (int) $total;
}

// Ambil email admin & pimpinan yang aktif (penerima notifikasi)
function getNotificationRecipients() {
    $conn = getDBConnection();
    $result = $conn->query("SELECT id, email, full_name FROM users WHERE role IN ('admin','pimpinan') AND status = 'ACTIVE'");
    $recipients = [];
    while ($row = $result->fetch_assoc()) {
        $recipients[] = $row;
    }
    $conn->close();
    return $recipients;
}

// Bangun isi email notifikasi kadaluarsa untuk satu item
function buildExpiryEmailBody($item) {
    $statusLabel = $item['expiry_info']['status'] === 'EXPIRED' ? 'SUDAH KADALUARSA' : 'AKAN KADALUARSA (H-' . $item['expiry_info']['days'] . ')';
    $color = $item['expiry_info']['status'] === 'EXPIRED' ? '#ef4444' : '#f59e0b';
    $expiryDateFormatted = date('d/m/Y', strtotime($item['expiry_date']));

    return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                <h2 style='color: {$color};'>⚠️ Notifikasi Barang Kadaluarsa</h2>
                <p>Status: <strong style='color: {$color};'>{$statusLabel}</strong></p>
                <table style='background-color: #f4f4f4; padding: 10px; border-radius: 5px; width: 100%; border-collapse: collapse;'>
                    <tr><td style='padding: 6px;'><strong>Nama Barang</strong></td><td style='padding: 6px;'>" . htmlspecialchars($item['item_name']) . "</td></tr>
                    <tr><td style='padding: 6px;'><strong>Part Number</strong></td><td style='padding: 6px;'>" . htmlspecialchars($item['part_number']) . "</td></tr>
                    <tr><td style='padding: 6px;'><strong>Lokasi</strong></td><td style='padding: 6px;'>" . htmlspecialchars($item['location']) . "</td></tr>
                    <tr><td style='padding: 6px;'><strong>Sisa Stock</strong></td><td style='padding: 6px;'>" . number_format($item['current_quantity']) . " " . htmlspecialchars($item['unit']) . "</td></tr>
                    <tr><td style='padding: 6px;'><strong>Tanggal Kadaluarsa</strong></td><td style='padding: 6px;'>{$expiryDateFormatted}</td></tr>
                </table>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . BASE_URL . "/expiry_notification.php' 
                       style='background-color: {$color}; color: white; padding: 12px 30px; 
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Lihat Detail
                    </a>
                </div>
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                <p style='color: #666; font-size: 14px;'>
                    Salam,<br>
                    <strong>Sistem Manajemen Logistik</strong><br>
                    PT. Sarana Karya Dua Satu
                </p>
            </div>
        </body>
        </html>
    ";
}

// Proses utama: cek barang kadaluarsa/akan kadaluarsa, kirim email jika belum pernah dikirim
// untuk kombinasi (inventory_stock_id, notif_type) tersebut. Dipanggil oleh cron harian.
function processExpiryNotifications($warningDays = 30) {
    $items = getExpiringStockItems($warningDays);
    if (empty($items)) {
        return ['checked' => 0, 'emails_sent' => 0];
    }

    $recipients = getNotificationRecipients();
    $conn = getDBConnection();
    $emailsSent = 0;

    foreach ($items as $item) {
        $notifType = $item['expiry_info']['status']; // EXPIRED atau WARNING

        // Cek apakah notifikasi untuk item+status ini sudah pernah dikirim
        $check = $conn->prepare("SELECT id FROM expiry_notifications WHERE inventory_stock_id = ? AND notif_type = ?");
        $check->bind_param("is", $item['id'], $notifType);
        $check->execute();
        $already = $check->get_result()->fetch_assoc();
        $check->close();

        if ($already) {
            continue; // sudah pernah dikirim untuk status ini, skip
        }

        // Kirim email ke semua admin/pimpinan
        $subject = ($notifType === 'EXPIRED' ? '🔴 Barang Kadaluarsa: ' : '🟡 Barang Akan Kadaluarsa (H-' . $item['expiry_info']['days'] . '): ') . $item['item_name'];
        $body = buildExpiryEmailBody($item);

        $anySent = false;
        foreach ($recipients as $recipient) {
            if (sendEmail($recipient['email'], $subject, $body)) {
                $anySent = true;
            }
        }

        // Catat notifikasi (baik sukses maupun ada percobaan) agar tidak spam berulang tiap hari
        $insert = $conn->prepare("INSERT INTO expiry_notifications (inventory_stock_id, notif_type, email_sent) VALUES (?, ?, ?)");
        $emailSentFlag = $anySent ? 1 : 0;
        $insert->bind_param("isi", $item['id'], $notifType, $emailSentFlag);
        $insert->execute();
        $insert->close();

        if ($anySent) {
            $emailsSent++;
        }
    }

    $conn->close();
    return ['checked' => count($items), 'emails_sent' => $emailsSent];
}