<?php
session_start();
require_once 'includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'] ?? null;
$user = $userId ? getUserById($userId) : null;
$conn = getDBConnection();

$currentRole = getCurrentUserRole();
$canManage = userCanManage($currentRole);
$expiring_count = getExpiryAlertCount();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate input
    if (empty($full_name)) {
        $error = 'Nama lengkap tidak boleh kosong';
    } elseif (empty($email)) {
        $error = 'Email tidak boleh kosong';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid';
    } else {
        // Check if email already exists (except current user)
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Email sudah digunakan oleh pengguna lain';
        } else {
            // Update profile
            if (!empty($new_password)) {
                // Verify current password
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $userData = $result->fetch_assoc();
                
                if (!password_verify($current_password, $userData['password'])) {
                    $error = 'Password saat ini tidak sesuai';
                } elseif (strlen($new_password) < 6) {
                    $error = 'Password baru minimal 6 karakter';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'Konfirmasi password tidak cocok';
                } else {
                    // Update with new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("sssi", $full_name, $email, $hashed_password, $userId);
                    
                    if ($stmt->execute()) {
                        $success = 'Profil dan password berhasil diperbarui!';
                        $user = getUserById($userId); // Refresh user data
                        $_SESSION['user_name'] = $full_name; // Update session
                    } else {
                        $error = 'Gagal memperbarui profil';
                    }
                }
            } else {
                // Update without password change
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssi", $full_name, $email, $userId);
                
                if ($stmt->execute()) {
                    $success = 'Profil berhasil diperbarui!';
                    $user = getUserById($userId); // Refresh user data
                    $_SESSION['user_name'] = $full_name; // Update session
                } else {
                    $error = 'Gagal memperbarui profil';
                }
            }
        }
        $stmt->close();
    }
}

// Get user statistics
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM incoming_goods WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$total_incoming = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM outgoing_goods WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$total_outgoing = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM inventory_stock WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$total_stock = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$conn->close();

$displayName = is_array($user) && !empty($user['full_name']) ? $user['full_name'] : ($_SESSION['user_name'] ?? 'Pengguna');
$userEmail = is_array($user) && !empty($user['email']) ? $user['email'] : '';
$createdAt = is_array($user) && !empty($user['created_at']) ? $user['created_at'] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil — SKDS Logistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; margin: 0; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(16,185,129,0.4); border-radius: 99px; }

        .sidebar {
            width: 260px; min-height: 100vh;
            background: linear-gradient(180deg, #052e16 0%, #064e3b 60%, #065f46 100%);
            border-right: 1px solid rgba(16,185,129,0.15);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; bottom: 0; z-index: 40;
            transition: transform 0.3s ease;
        }
        .sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; }
        .sidebar-logo img { width: 40px; height: 40px; object-fit: contain; border-radius: 10px; }
        .sidebar-logo-text { font-family: 'Plus Jakarta Sans', sans-serif; }
        .sidebar-logo-text h1 { color: #fff; font-size: 14px; font-weight: 700; letter-spacing: 0.02em; margin: 0; line-height: 1.2; }
        .sidebar-logo-text p { color: #6ee7b7; font-size: 10px; font-weight: 500; margin: 0; letter-spacing: 0.05em; text-transform: uppercase; }
        .sidebar-section { padding: 20px 16px 8px; }
        .sidebar-section-label { color: rgba(110,231,183,0.5); font-size: 9px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; padding: 0 8px; margin-bottom: 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px; color: rgba(209,250,229,0.7); text-decoration: none; font-size: 13.5px; font-weight: 500; transition: all 0.2s ease; margin-bottom: 2px; position: relative; }
        .nav-item:hover { background: rgba(16,185,129,0.12); color: #d1fae5; }
        .nav-item.active { background: linear-gradient(135deg, rgba(16,185,129,0.25), rgba(5,150,105,0.15)); color: #fff; border: 1px solid rgba(16,185,129,0.25); }
        .nav-item.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 60%; background: #10b981; border-radius: 0 4px 4px 0; }
        .nav-item i { width: 18px; text-align: center; font-size: 14px; }
        .nav-item .badge { margin-left: auto; background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; min-width: 20px; text-align: center; }
        .sidebar-footer { margin-top: auto; padding: 16px; border-top: 1px solid rgba(255,255,255,0.07); }
        .user-card { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 12px; display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: #fff; font-weight: 700; flex-shrink: 0; }
        .user-info { flex: 1; min-width: 0; }
        .user-info .name { color: #fff; font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-info .role { color: #6ee7b7; font-size: 10.5px; }
        .logout-btn { color: rgba(209,250,229,0.5); font-size: 14px; transition: color 0.2s; text-decoration: none; flex-shrink: 0; }
        .logout-btn:hover { color: #f87171; }

        .main-content { margin-left: 260px; min-height: 100vh; background: #0d1117; }
        .topbar { background: rgba(13,17,23,0.95); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,0.06); padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 30; }
        .topbar-title { font-family: 'Plus Jakarta Sans', sans-serif; color: #fff; font-size: 18px; font-weight: 700; }
        .topbar-sub { color: #4b5563; font-size: 12px; margin-top: 1px; }
        .page-body { padding: 28px 32px; }

        .panel { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 24px; }
        .panel-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; }
        .panel-title-group { display: flex; align-items: center; gap: 12px; }
        .panel-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(16,185,129,0.15); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid rgba(16,185,129,0.2); }
        .panel-title { color: #fff; font-size: 18px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; }

        .input-field, select.input-field, textarea.input-field {
            width: 100%; background: #0d1117; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px;
            padding: 10px 14px; color: #e5e7eb; font-size: 13.5px; transition: border-color 0.2s;
        }
        .input-field:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
        .input-field[readonly] { background: rgba(16,185,129,0.08); border-color: rgba(16,185,129,0.25); color: #6ee7b7; cursor: not-allowed; }
        .field-label { display: block; color: #9ca3af; font-size: 12px; font-weight: 600; margin-bottom: 6px; }
        .field-hint { color: #4b5563; font-size: 11px; margin-top: 4px; }

        .form-section { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 22px; margin-bottom: 18px; }
        .form-section-title { color: #e5e7eb; font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .form-section-title i { color: #10b981; }

        .btn-primary { background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 11px 22px; border-radius: 10px; font-weight: 600; font-size: 13.5px; border: none; cursor: pointer; transition: opacity 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-secondary { background: rgba(255,255,255,0.06); color: #d1d5db; padding: 11px 22px; border-radius: 10px; font-weight: 600; font-size: 13.5px; border: 1px solid rgba(255,255,255,0.1); cursor: pointer; transition: background 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); }
        .btn-sm { padding: 5px 12px; font-size: 11.5px; border-radius: 7px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-edit { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.25); }
        .btn-edit:hover { background: rgba(59,130,246,0.25); }
        .btn-delete { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
        .btn-delete:hover { background: rgba(239,68,68,0.25); }

        .alert { border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-size: 13.5px; }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        .alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }

        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px; }
        .stat-card { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 14px; padding: 20px; }
        .stat-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .stat-card-label { color: #6b7280; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .stat-card-icon { color: rgba(255,255,255,0.15); font-size: 18px; }
        .stat-card-value { color: #fff; font-size: 22px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; }

        .filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .select-pill { background: #0d1117; border: 1px solid rgba(255,255,255,0.1); color: #d1d5db; border-radius: 9px; padding: 9px 14px; font-size: 13px; font-weight: 500; }
        .select-pill:focus { outline: none; border-color: #10b981; }

        .filter-chip { background: rgba(255,255,255,0.04); color: #9ca3af; border: 1px solid rgba(255,255,255,0.08); border-radius: 9px; padding: 8px 16px; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .filter-chip.active { background: rgba(16,185,129,0.18); color: #6ee7b7; border-color: rgba(16,185,129,0.4); }
        .filter-chip:hover:not(.active) { background: rgba(255,255,255,0.08); }

        .data-table { width: 100%; font-size: 12.5px; border-collapse: collapse; }
        .data-table thead th { background: rgba(255,255,255,0.02); color: #6b7280; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 12px 10px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .data-table tbody td { padding: 12px 10px; color: #d1d5db; border-bottom: 1px solid rgba(255,255,255,0.04); }
        .data-table tbody tr:hover { background: rgba(255,255,255,0.02); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .mono { font-family: 'SF Mono', Consolas, monospace; color: #6ee7b7; }

        .group-card { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 14px; overflow: hidden; margin-bottom: 20px; }
        .group-header { background: rgba(16,185,129,0.08); border-bottom: 1px solid rgba(16,185,129,0.15); padding: 14px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; }
        .group-header-left { color: #e5e7eb; font-size: 13px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .group-header-left .sep { color: #374151; }
        .group-header-right { color: #6ee7b7; font-weight: 700; font-size: 13px; }
        .total-row td { background: rgba(245,158,11,0.06); color: #fbbf24; font-weight: 700; border-top: 2px solid rgba(245,158,11,0.25); }

        .badge-pill { font-size: 10.5px; font-weight: 700; padding: 3px 10px; border-radius: 99px; display: inline-flex; align-items: center; gap: 4px; }
        .badge-cash { background: rgba(16,185,129,0.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.3); }
        .badge-credit { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
        .badge-aman { background: rgba(16,185,129,0.15); color: #6ee7b7; }
        .badge-sedang { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-kritis { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-location { background: rgba(6,182,212,0.12); color: #67e8f9; border: 1px solid rgba(6,182,212,0.25); font-size: 10.5px; font-weight: 600; padding: 3px 10px; border-radius: 99px; }

        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 40px; color: #374151; margin-bottom: 14px; }
        .empty-state h4 { color: #9ca3af; font-size: 15px; font-weight: 600; margin: 0 0 6px; }
        .empty-state p { color: #4b5563; font-size: 13px; margin: 0 0 18px; }

        .info-box { background: rgba(6,182,212,0.08); border: 1px solid rgba(6,182,212,0.2); border-radius: 10px; padding: 12px 16px; color: #67e8f9; font-size: 12.5px; margin-bottom: 16px; }
        .calc-box { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); border-radius: 10px; padding: 14px 16px; color: #a7f3d0; font-size: 12.5px; line-height: 1.6; }
        .calc-box strong { color: #fff; }

        .sidebar-toggle { display: none; position: fixed; top: 16px; left: 16px; z-index: 50; background: #065f46; border: none; color: #fff; width: 40px; height: 40px; border-radius: 10px; font-size: 16px; cursor: pointer; }
        .sidebar-overlay { display: none; }

        @media (max-width: 1024px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-toggle { display: flex; align-items: center; justify-content: center; }
            .sidebar-overlay { display: block; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 35; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
            .sidebar-overlay.open { opacity: 1; pointer-events: all; }
            .main-content { margin-left: 0; }
            .page-body { padding: 20px 16px; }
            .topbar { padding: 0 16px 0 64px; }
            .stat-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
        }
    </style>
    <style>
        .profile-card { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 28px; text-align: center; }
        .profile-avatar { width: 100px; height: 100px; margin: 0 auto 16px; border-radius: 24px; background: linear-gradient(135deg, #10b981, #059669); display: flex; align-items: center; justify-content: center; font-size: 40px; color: #fff; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; }
        .profile-name { color: #fff; font-size: 19px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; margin-bottom: 8px; }
        .profile-email { color: #6b7280; font-size: 13px; margin-bottom: 16px; }
        .profile-meta { background: rgba(255,255,255,0.03); border-radius: 10px; padding: 10px 14px; color: #6b7280; font-size: 12px; }

        .activity-stat { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 16px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .activity-stat:last-child { margin-bottom: 0; }
        .activity-stat-left { display: flex; align-items: center; gap: 10px; color: #9ca3af; font-size: 13px; }
        .activity-stat-left i { width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .activity-stat-value { color: #fff; font-size: 20px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body>
<button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="images/logo-skds.jpeg" alt="Logo SKDS">
        <div class="sidebar-logo-text">
            <h1>SKDS Logistik</h1>
            <p>PT. Sarana Karya Dua Satu</p>
        </div>
    </div>

    <div class="sidebar-section" style="flex: 1; overflow-y: auto;">
        <div class="sidebar-section-label">Menu Utama</div>

        <a href="dashboard.php" class="nav-item ">
            <i class="fas fa-gauge-high"></i> Dashboard
        </a>
        <a href="incoming_goods.php" class="nav-item ">
            <i class="fas fa-boxes-stacking"></i> Barang Masuk
        </a>
        <a href="inventory_stock.php" class="nav-item ">
            <i class="fas fa-warehouse"></i> Inventory Stock
        </a>
        <a href="outgoing_goods.php" class="nav-item ">
            <i class="fas fa-truck-fast"></i> Barang Keluar
        </a>
        <a href="expiry_notification.php" class="nav-item ">
            <i class="fas fa-triangle-exclamation"></i> Notifikasi Kadaluarsa
            <?php if ($expiring_count > 0): ?><span class="badge"><?php echo $expiring_count; ?></span><?php endif; ?>
        </a>

        <?php if ($canManage): ?>
        <div class="sidebar-section-label" style="margin-top: 20px;">Laporan & Aksi</div>
        <a href="incoming_goods.php?action=create" class="nav-item">
            <i class="fas fa-plus-circle"></i> Tambah Barang
        </a>
        <a href="outgoing_goods.php?action=create" class="nav-item">
            <i class="fas fa-truck-loading"></i> Proses Keluar
        </a>
        <?php endif; ?>

        <div class="sidebar-section-label" style="margin-top: 20px;">Akun</div>
        <a href="profile.php" class="nav-item active">
            <i class="fas fa-user-circle"></i> Profil Saya
        </a>
        <?php if ($currentRole === 'pimpinan'): ?>
        <a href="manage_users.php" class="nav-item">
            <i class="fas fa-users-cog"></i> Kelola Pengguna
        </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="role"><?php echo htmlspecialchars(getRoleLabel($currentRole)); ?></div>
            </div>
            <a href="logout.php" class="logout-btn" title="Logout"><i class="fas fa-arrow-right-from-bracket"></i></a>
        </div>
    </div>
</aside>

<div class="main-content">
    <header class="topbar">
        <div>
            <div class="topbar-title">Profil Saya</div>
            <div class="topbar-sub">Kelola informasi akun dan keamanan</div>
        </div>
    </header>

    <div class="page-body">

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Left: Profile Info -->
            <div class="lg:col-span-1" style="display:flex; flex-direction:column; gap:20px;">

                <div class="profile-card">
                    <div class="profile-avatar"><?php echo strtoupper(substr($displayName,0,1)); ?></div>
                    <div class="profile-name"><?php echo htmlspecialchars($displayName); ?></div>
                    <div class="profile-email"><i class="fas fa-envelope" style="margin-right:6px;"></i><?php echo htmlspecialchars($userEmail); ?></div>
                    <div class="profile-meta"><i class="fas fa-calendar-alt" style="margin-right:6px;"></i>Bergabung sejak <?php echo date('d M Y', strtotime($createdAt)); ?></div>
                </div>

                <div class="panel">
                    <div class="form-section-title" style="margin-bottom:16px;"><i class="fas fa-chart-bar"></i> Statistik Aktivitas</div>

                    <div class="activity-stat">
                        <div class="activity-stat-left">
                            <i class="fas fa-box" style="background:rgba(16,185,129,0.15); color:#10b981;"></i>
                            Barang Masuk
                        </div>
                        <div class="activity-stat-value"><?php echo number_format($total_incoming); ?></div>
                    </div>
                    <div class="activity-stat">
                        <div class="activity-stat-left">
                            <i class="fas fa-warehouse" style="background:rgba(20,184,166,0.15); color:#14b8a6;"></i>
                            Inventory Stock
                        </div>
                        <div class="activity-stat-value"><?php echo number_format($total_stock); ?></div>
                    </div>
                    <div class="activity-stat">
                        <div class="activity-stat-left">
                            <i class="fas fa-shipping-fast" style="background:rgba(245,158,11,0.15); color:#f59e0b;"></i>
                            Barang Keluar
                        </div>
                        <div class="activity-stat-value"><?php echo number_format($total_outgoing); ?></div>
                    </div>
                </div>

            </div>

            <!-- Right: Edit Form -->
            <div class="lg:col-span-2">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon"><i class="fas fa-pen"></i></div>
                            <div class="panel-title">Edit Profil</div>
                        </div>
                    </div>

                    <form method="POST" action="">

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-user-circle"></i> Informasi Pribadi</div>
                            <div style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <label class="field-label">Nama Lengkap *</label>
                                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($displayName); ?>" required class="input-field" placeholder="Masukkan nama lengkap">
                                </div>
                                <div>
                                    <label class="field-label">Email *</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($userEmail); ?>" required class="input-field" placeholder="email@example.com">
                                    <p class="field-hint">Email digunakan untuk login ke sistem</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-lock"></i> Ubah Password</div>
                            <p style="color:#6b7280; font-size:12.5px; margin-bottom:16px;">Kosongkan jika tidak ingin mengubah password</p>
                            <div style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <label class="field-label">Password Saat Ini</label>
                                    <input type="password" name="current_password" id="current_password" class="input-field" placeholder="Masukkan password saat ini">
                                </div>
                                <div>
                                    <label class="field-label">Password Baru</label>
                                    <input type="password" name="new_password" id="new_password" class="input-field" placeholder="Minimal 6 karakter">
                                    <p class="field-hint">Minimal 6 karakter</p>
                                </div>
                                <div>
                                    <label class="field-label">Konfirmasi Password Baru</label>
                                    <input type="password" name="confirm_password" id="confirm_password" class="input-field" placeholder="Ketik ulang password baru">
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3" style="margin-top:8px;">
                            <button type="submit" class="btn-primary" style="flex:1;"><i class="fas fa-save"></i> Simpan Perubahan</button>
                            <a href="dashboard.php" class="btn-secondary" style="flex:1;"><i class="fas fa-times"></i> Batal</a>
                        </div>

                    </form>
                </div>
            </div>

        </div>

    </div><!-- /page-body -->
</div><!-- /main-content -->

<script>
const toggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
toggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });

const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const currentPassword = document.getElementById('current_password');

function validatePassword() {
    if (newPassword.value !== '' || confirmPassword.value !== '') {
        if (currentPassword.value === '') {
            currentPassword.setCustomValidity('Password saat ini harus diisi jika ingin mengubah password');
        } else {
            currentPassword.setCustomValidity('');
        }
        if (newPassword.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity('Password tidak cocok');
        } else if (newPassword.value !== '' && newPassword.value.length < 6) {
            newPassword.setCustomValidity('Password minimal 6 karakter');
        } else {
            confirmPassword.setCustomValidity('');
            newPassword.setCustomValidity('');
        }
    } else {
        currentPassword.setCustomValidity('');
        newPassword.setCustomValidity('');
        confirmPassword.setCustomValidity('');
    }
}

newPassword.addEventListener('input', validatePassword);
confirmPassword.addEventListener('input', validatePassword);
currentPassword.addEventListener('input', validatePassword);
</script>
</body>
</html>