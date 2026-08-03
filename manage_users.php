<?php
session_start();
require_once 'includes/functions.php';
requireLogin();

// Hanya 'pimpinan' yang boleh mengelola pengguna
requireRole('pimpinan');

$currentRole = getCurrentUserRole();
$canManage = userCanManage($currentRole);
$expiring_count = getExpiryAlertCount();
$currentPage = 'manage_users';

// ========================================
// HANDLE ACTIONS (POST)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    if ($action === 'update_role' && $targetId) {
        $newRole = sanitizeInput($_POST['role'] ?? '');

        if ($targetId === (int) $_SESSION['user_id']) {
            setFlashMessage('error', 'Anda tidak dapat mengubah role akun sendiri.');
        } elseif (updateUserRole($targetId, $newRole)) {
            logActivity($_SESSION['user_id'], 'UPDATE_ROLE', "Mengubah role user ID $targetId menjadi $newRole");
            setFlashMessage('success', 'Role pengguna berhasil diperbarui.');
        } else {
            setFlashMessage('error', 'Gagal memperbarui role. Role tidak valid.');
        }
    } elseif ($action === 'update_status' && $targetId) {
        $newStatus = sanitizeInput($_POST['status'] ?? '');
        $allowedStatus = ['ACTIVE', 'PENDING', 'SUSPENDED'];

        if ($targetId === (int) $_SESSION['user_id']) {
            setFlashMessage('error', 'Anda tidak dapat mengubah status akun sendiri.');
        } elseif (in_array($newStatus, $allowedStatus, true) && updateUserStatus($targetId, $newStatus)) {
            logActivity($_SESSION['user_id'], 'UPDATE_STATUS', "Mengubah status user ID $targetId menjadi $newStatus");
            setFlashMessage('success', 'Status pengguna berhasil diperbarui.');
        } else {
            setFlashMessage('error', 'Gagal memperbarui status.');
        }
    } elseif ($action === 'create_user') {
        $newFullName = sanitizeInput($_POST['full_name'] ?? '');
        $newEmail = sanitizeInput($_POST['email'] ?? '');
        $newPhone = sanitizeInput($_POST['phone'] ?? '');
        $newRole = sanitizeInput($_POST['role'] ?? 'staff');
        $newPassword = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if (empty($newPassword)) {
            setFlashMessage('error', 'Password wajib diisi.');
        } elseif ($newPassword !== $confirmPassword) {
            setFlashMessage('error', 'Password dan konfirmasi password tidak cocok.');
        } else {
            $passwordCheck = validatePassword($newPassword);

            if (!$passwordCheck['valid']) {
                setFlashMessage('error', $passwordCheck['message']);
            } else {
                $result = createUserByAdmin($newFullName, $newEmail, $newPhone, $newPassword, $newRole);

                if ($result['success']) {
                    $createdUser = getUserByEmail($newEmail);
                    $emailSent = sendNewAccountEmail($createdUser, $newPassword);
                    logActivity($_SESSION['user_id'], 'CREATE_USER', "Membuat akun baru: $newEmail ($newRole)");

                    if ($emailSent) {
                        setFlashMessage('success', "Akun berhasil dibuat untuk $newFullName. Kredensial telah dikirim ke email $newEmail.");
                    } else {
                        setFlashMessage('error', "Akun berhasil dibuat untuk $newFullName, TAPI email kredensial GAGAL terkirim ke $newEmail. Silakan cek pengaturan SMTP, atau catat manual: Email: $newEmail | Password: $newPassword");
                    }
                } else {
                    setFlashMessage('error', $result['message']);
                }
            }
        }
    } elseif ($action === 'delete_user' && $targetId) {
        if ($targetId === (int) $_SESSION['user_id']) {
            setFlashMessage('error', 'Anda tidak dapat menghapus akun sendiri.');
        } elseif (deleteUser($targetId)) {
            logActivity($_SESSION['user_id'], 'DELETE_USER', "Menghapus user ID $targetId");
            setFlashMessage('success', 'Pengguna berhasil dihapus.');
        } else {
            setFlashMessage('error', 'Gagal menghapus pengguna.');
        }
    }

    header('Location: manage_users.php');
    exit();
}

$flash = getFlashMessage();
$users = getAllUsers();
$displayName = $_SESSION['user_name'] ?? 'Pengguna';

$roleLabels = [
    'admin'    => 'Administrator',
    'pimpinan' => 'Pimpinan',
    'staff'    => 'Staff',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengguna — Sistem Manajemen Logistik</title>
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
        .sidebar-logo-text h1 { color: #fff; font-size: 14px; font-weight: 700; letter-spacing: 0.02em; margin: 0; line-height: 1.2; font-family: 'Plus Jakarta Sans', sans-serif; }
        .sidebar-logo-text p { color: #6ee7b7; font-size: 10px; font-weight: 500; margin: 0; letter-spacing: 0.05em; text-transform: uppercase; }
        .sidebar-section { padding: 20px 16px 8px; }
        .sidebar-section-label { color: rgba(110,231,183,0.5); font-size: 9px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; padding: 0 8px; margin-bottom: 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px; color: rgba(209,250,229,0.7); text-decoration: none; font-size: 13.5px; font-weight: 500; transition: all 0.2s ease; margin-bottom: 2px; position: relative; }
        .nav-item:hover { background: rgba(16,185,129,0.12); color: #d1fae5; }
        .nav-item.active { background: linear-gradient(135deg, rgba(16,185,129,0.25), rgba(5,150,105,0.15)); color: #fff; border: 1px solid rgba(16,185,129,0.25); }
        .nav-item.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 60%; background: #10b981; border-radius: 0 4px 4px 0; }
        .nav-item i { width: 18px; text-align: center; font-size: 14px; }
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

        .alert { border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; font-size: 13px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }

        .panel { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 24px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .panel-title { color: #fff; font-size: 15px; font-weight: 600; }
        .panel-badge { color: #6ee7b7; font-size: 12px; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); padding: 4px 10px; border-radius: 99px; }

        table { width: 100%; border-collapse: collapse; }
        thead th { text-align: left; color: #6b7280; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        tbody td { padding: 12px; font-size: 13px; color: #e5e7eb; border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: middle; }
        tbody tr:hover { background: rgba(255,255,255,0.02); }
        .user-name { font-weight: 600; color: #fff; }
        .user-email { color: #6b7280; font-size: 11.5px; }

        select.role-select, select.status-select {
            background: #0d1117; color: #e5e7eb;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px; padding: 6px 8px; font-size: 12px;
        }
        .badge-role { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
        .badge-role.admin { background: rgba(6,182,212,0.15); color: #22d3ee; }
        .badge-role.pimpinan { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-role.staff { background: rgba(148,163,184,0.15); color: #cbd5e1; }
        .badge-status { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
        .badge-status.ACTIVE { background: rgba(16,185,129,0.15); color: #10b981; }
        .badge-status.PENDING { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .badge-status.SUSPENDED { background: rgba(239,68,68,0.15); color: #ef4444; }

        .btn-icon { background: transparent; border: none; color: #6b7280; cursor: pointer; font-size: 13px; padding: 6px; border-radius: 6px; transition: all 0.15s; }
        .btn-icon:hover { color: #f87171; background: rgba(239,68,68,0.1); }
        .you-tag { color: #4b5563; font-size: 10.5px; font-style: italic; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .page-body { padding: 20px 16px; }
            .topbar { padding: 0 16px; }
        }
    </style>
</head>
<body>

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
        <a href="dashboard.php" class="nav-item"><i class="fas fa-gauge-high"></i>Dashboard</a>
        <a href="incoming_goods.php" class="nav-item"><i class="fas fa-boxes-stacking"></i>Barang Masuk</a>
        <a href="inventory_stock.php" class="nav-item"><i class="fas fa-warehouse"></i>Inventory Stock</a>
        <a href="outgoing_goods.php" class="nav-item"><i class="fas fa-truck-fast"></i>Barang Keluar</a>
        <a href="expiry_notification.php" class="nav-item"><i class="fas fa-triangle-exclamation"></i>Notifikasi Kadaluarsa<?php if ($expiring_count > 0): ?><span class="badge"><?php echo $expiring_count; ?></span><?php endif; ?></a>

        <div class="sidebar-section-label" style="margin-top: 20px;">Laporan & Aksi</div>
        <a href="export_incoming.php" class="nav-item"><i class="fas fa-file-arrow-down"></i>Export Laporan</a>

        <div class="sidebar-section-label" style="margin-top: 20px;">Akun</div>
        <a href="profile.php" class="nav-item"><i class="fas fa-user-circle"></i>Profil Saya</a>
        <a href="manage_users.php" class="nav-item active"><i class="fas fa-users-cog"></i>Kelola Pengguna</a>
        <a href="activity_log.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i>Riwayat Aktivitas</a>
    </div>

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="role"><?php echo htmlspecialchars($roleLabels[$currentRole] ?? $currentRole); ?></div>
            </div>
            <a href="logout.php" class="logout-btn" title="Logout"><i class="fas fa-arrow-right-from-bracket"></i></a>
        </div>
    </div>
</aside>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="topbar-title">Kelola Pengguna</div>
            <div class="topbar-sub">Atur role dan status akun pengguna sistem</div>
        </div>
    </div>

    <div class="page-body">

        <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
            <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>

        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-user-plus" style="color:#10b981; margin-right:8px;"></i>Tambah Pengguna Baru</div>
            </div>

            <form method="POST" action="manage_users.php">
                <input type="hidden" name="action" value="create_user">
                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap: 14px;">
                    <div>
                        <label style="display:block; color:#9ca3af; font-size:11.5px; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">Nama Lengkap *</label>
                        <input type="text" name="full_name" required
                               style="width:100%; background:#0d1117; border:1px solid rgba(255,255,255,0.12); color:#e5e7eb; padding:9px 12px; border-radius:8px; font-size:13px;"
                               placeholder="Nama karyawan">
                    </div>
                    <div>
                        <label style="display:block; color:#9ca3af; font-size:11.5px; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">Email *</label>
                        <input type="email" name="email" required
                               style="width:100%; background:#0d1117; border:1px solid rgba(255,255,255,0.12); color:#e5e7eb; padding:9px 12px; border-radius:8px; font-size:13px;"
                               placeholder="nama@email.com">
                    </div>
                    <div>
                        <label style="display:block; color:#9ca3af; font-size:11.5px; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">No. Telepon</label>
                        <input type="text" name="phone"
                               style="width:100%; background:#0d1117; border:1px solid rgba(255,255,255,0.12); color:#e5e7eb; padding:9px 12px; border-radius:8px; font-size:13px;"
                               placeholder="08xxxxxxxxxx">
                    </div>
                    <div>
                        <label style="display:block; color:#9ca3af; font-size:11.5px; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">Role *</label>
                        <select name="role" required
                                style="width:100%; background:#0d1117; border:1px solid rgba(255,255,255,0.12); color:#e5e7eb; padding:9px 12px; border-radius:8px; font-size:13px;">
                            <?php foreach ($roleLabels as $roleKey => $roleLabel): ?>
                            <option value="<?php echo $roleKey; ?>" <?php echo $roleKey === 'staff' ? 'selected' : ''; ?>><?php echo $roleLabel; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; color:#9ca3af; font-size:11.5px; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">Password *</label>
                        <input type="password" name="password" required minlength="8"
                               style="width:100%; background:#0d1117; border:1px solid rgba(255,255,255,0.12); color:#e5e7eb; padding:9px 12px; border-radius:8px; font-size:13px;"
                               placeholder="Minimal 8 karakter, ada huruf besar, kecil & angka">
                    </div>
                    <div>
                        <label style="display:block; color:#9ca3af; font-size:11.5px; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">Konfirmasi Password *</label>
                        <input type="password" name="confirm_password" required minlength="8"
                               style="width:100%; background:#0d1117; border:1px solid rgba(255,255,255,0.12); color:#e5e7eb; padding:9px 12px; border-radius:8px; font-size:13px;"
                               placeholder="Ulangi password">
                    </div>
                </div>
                <button type="submit"
                        style="margin-top:16px; background:#10b981; color:#fff; font-weight:600; font-size:13px; padding:10px 20px; border:none; border-radius:8px; cursor:pointer;">
                    <i class="fas fa-plus-circle"></i> Buat Akun
                </button>
                <span style="color:#4b5563; font-size:11.5px; margin-left:12px;">
                    Password ditentukan sendiri oleh Anda. Kredensial akan dikirim ke email karyawan.
                </span>
            </form>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Daftar Pengguna</div>
                <div class="panel-badge"><?php echo count($users); ?> Pengguna</div>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Terdaftar</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #4b5563; padding: 30px;">Belum ada pengguna.</td>
                        </tr>
                        <?php else: foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="user-name"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($u['email']); ?></div>
                            </td>
                            <td>
                                <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                    <span class="badge-role <?php echo htmlspecialchars($u['role']); ?>"><?php echo htmlspecialchars($roleLabels[$u['role']] ?? $u['role']); ?></span>
                                    <span class="you-tag">(Anda)</span>
                                <?php else: ?>
                                    <form method="POST" action="manage_users.php" style="display:inline;">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <select name="role" class="role-select" onchange="this.form.submit()">
                                            <?php foreach ($roleLabels as $roleKey => $roleLabel): ?>
                                            <option value="<?php echo $roleKey; ?>" <?php echo $u['role'] === $roleKey ? 'selected' : ''; ?>>
                                                <?php echo $roleLabel; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                    <span class="badge-status <?php echo htmlspecialchars($u['status']); ?>"><?php echo htmlspecialchars($u['status']); ?></span>
                                <?php else: ?>
                                    <form method="POST" action="manage_users.php" style="display:inline;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <select name="status" class="status-select" onchange="this.form.submit()">
                                            <?php foreach (['ACTIVE', 'PENDING', 'SUSPENDED'] as $statusOpt): ?>
                                            <option value="<?php echo $statusOpt; ?>" <?php echo $u['status'] === $statusOpt ? 'selected' : ''; ?>>
                                                <?php echo $statusOpt; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatDate($u['created_at']); ?></td>
                            <td style="text-align: right;">
                                <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                <form method="POST" action="manage_users.php" style="display:inline;" onsubmit="return confirm('Hapus pengguna ini secara permanen?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <button type="submit" class="btn-icon" title="Hapus pengguna">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

</body>
</html>