<?php
session_start();
require_once 'includes/functions.php';
requireLogin();

// Hanya 'admin' dan 'pimpinan' yang boleh melihat riwayat aktivitas
requireRole(['admin', 'pimpinan']);

$currentRole = getCurrentUserRole();
$canManage = userCanManage($currentRole);
$expiring_count = getExpiryAlertCount();
$currentPage = 'activity_log';
$displayName = $_SESSION['user_name'] ?? 'Pengguna';

$roleLabels = [
    'admin'    => 'Administrator',
    'pimpinan' => 'Pimpinan',
    'staff'    => 'Staff',
];

$actionLabels = [
    'LOGIN'         => 'Login',
    'LOGOUT'        => 'Logout',
    'CREATE_USER'   => 'Buat Pengguna',
    'UPDATE_ROLE'   => 'Ubah Role',
    'UPDATE_STATUS' => 'Ubah Status',
    'DELETE_USER'   => 'Hapus Pengguna',
];

// ========================================
// FILTER
// ========================================
$filterUser = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$filterAction = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
$filterDateFrom = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';

// ========================================
// PAGINATION
// ========================================
$perPage = 25;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// ========================================
// BUILD QUERY
// ========================================
$conn = getDBConnection();

$where = [];
$params = [];
$types = '';

if ($filterUser > 0) {
    $where[] = 'al.user_id = ?';
    $params[] = $filterUser;
    $types .= 'i';
}
if (!empty($filterAction)) {
    $where[] = 'al.action = ?';
    $params[] = $filterAction;
    $types .= 's';
}
if (!empty($filterDateFrom)) {
    $where[] = 'DATE(al.created_at) >= ?';
    $params[] = $filterDateFrom;
    $types .= 's';
}
if (!empty($filterDateTo)) {
    $where[] = 'DATE(al.created_at) <= ?';
    $params[] = $filterDateTo;
    $types .= 's';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count untuk pagination
$countSql = "SELECT COUNT(*) as total FROM activity_logs al $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'] ?? 0;
$countStmt->close();
$totalPages = max(1, ceil($totalRows / $perPage));

// Data aktivitas
$sql = "SELECT al.*, u.full_name, u.email, u.role 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        $whereSql 
        ORDER BY al.created_at DESC 
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$allTypes = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// List user untuk dropdown filter
$allUsers = getAllUsers();

$conn->close();

// Helper untuk build query string filter (dipakai di link pagination)
function buildFilterQuery($overrides = []) {
    $current = [
        'user_id' => $_GET['user_id'] ?? '',
        'action' => $_GET['action'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ];
    $merged = array_merge($current, $overrides);
    return http_build_query(array_filter($merged, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Aktivitas — Sistem Manajemen Logistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; margin: 0; }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(16,185,129,0.4); border-radius: 99px; }

        .sidebar { width: 260px; min-height: 100vh; background: linear-gradient(180deg, #052e16 0%, #064e3b 60%, #065f46 100%); border-right: 1px solid rgba(16,185,129,0.15); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 40; transition: transform 0.3s ease; }
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

        .panel { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 24px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .panel-title { color: #fff; font-size: 15px; font-weight: 600; }
        .panel-badge { color: #6ee7b7; font-size: 12px; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); padding: 4px 10px; border-radius: 99px; }

        .filter-bar { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px; }
        .filter-bar select, .filter-bar input {
            background: #0d1117; color: #e5e7eb; border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px; padding: 8px 10px; font-size: 12.5px; width: 100%;
        }
        .filter-bar button {
            background: #10b981; color: #fff; border: none; border-radius: 8px;
            padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer;
        }
        .filter-reset { color: #6b7280; font-size: 11.5px; text-decoration: none; align-self: center; }
        .filter-reset:hover { color: #9ca3af; }

        table { width: 100%; border-collapse: collapse; }
        thead th { text-align: left; color: #6b7280; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        tbody td { padding: 12px; font-size: 13px; color: #e5e7eb; border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: top; }
        tbody tr:hover { background: rgba(255,255,255,0.02); }
        .user-name { font-weight: 600; color: #fff; }
        .user-email { color: #6b7280; font-size: 11.5px; }
        .desc-text { color: #9ca3af; font-size: 12px; }
        .ip-text { color: #6b7280; font-size: 11.5px; font-family: monospace; }
        .time-text { color: #9ca3af; font-size: 12px; white-space: nowrap; }

        .badge-action { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 99px; white-space: nowrap; }
        .badge-action.LOGIN { background: rgba(16,185,129,0.15); color: #10b981; }
        .badge-action.LOGOUT { background: rgba(148,163,184,0.15); color: #cbd5e1; }
        .badge-action.CREATE_USER { background: rgba(6,182,212,0.15); color: #22d3ee; }
        .badge-action.UPDATE_ROLE { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-action.UPDATE_STATUS { background: rgba(139,92,246,0.15); color: #a78bfa; }
        .badge-action.DELETE_USER { background: rgba(239,68,68,0.15); color: #ef4444; }

        .pagination { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
        .page-link {
            color: #9ca3af; font-size: 12.5px; padding: 6px 12px; border-radius: 8px;
            text-decoration: none; border: 1px solid rgba(255,255,255,0.08);
        }
        .page-link:hover { background: rgba(16,185,129,0.1); color: #10b981; }
        .page-link.active { background: #10b981; color: #fff; border-color: #10b981; }
        .page-link.disabled { opacity: 0.35; pointer-events: none; }

        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 0; text-align: center; }
        .empty-state i { font-size: 36px; color: #374151; margin-bottom: 12px; }
        .empty-state h4 { color: #6b7280; font-size: 13px; font-weight: 600; margin: 0 0 4px; }

        @media (max-width: 1024px) {
            .filter-bar { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .page-body { padding: 20px 16px; }
            .topbar { padding: 0 16px; }
            .filter-bar { grid-template-columns: 1fr; }
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
        <?php if ($currentRole === 'pimpinan'): ?>
        <a href="manage_users.php" class="nav-item"><i class="fas fa-users-cog"></i>Kelola Pengguna</a>
        <?php endif; ?>
        <a href="activity_log.php" class="nav-item active"><i class="fas fa-clock-rotate-left"></i>Riwayat Aktivitas</a>
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
    <div class="topbar">
        <div>
            <div class="topbar-title">Riwayat Aktivitas</div>
            <div class="topbar-sub">Log aktivitas seluruh pengguna di sistem</div>
        </div>
    </div>

    <div class="page-body">

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Log Aktivitas</div>
                <div class="panel-badge"><?php echo number_format($totalRows); ?> Aktivitas</div>
            </div>

            <!-- Filter -->
            <form method="GET" action="activity_log.php" class="filter-bar">
                <select name="user_id">
                    <option value="">Semua Pengguna</option>
                    <?php foreach ($allUsers as $u): ?>
                    <option value="<?php echo (int) $u['id']; ?>" <?php echo $filterUser === (int) $u['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <select name="action">
                    <option value="">Semua Aksi</option>
                    <?php foreach ($actionLabels as $actKey => $actLabel): ?>
                    <option value="<?php echo $actKey; ?>" <?php echo $filterAction === $actKey ? 'selected' : ''; ?>>
                        <?php echo $actLabel; ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" placeholder="Dari tanggal">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" placeholder="Sampai tanggal">

                <div style="display:flex; gap:8px;">
                    <button type="submit"><i class="fas fa-filter"></i> Filter</button>
                    <a href="activity_log.php" class="filter-reset">Reset</a>
                </div>
            </form>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Pengguna</th>
                            <th>Aksi</th>
                            <th>Deskripsi</th>
                            <th>IP Address</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h4>Belum ada riwayat aktivitas</h4>
                                </div>
                            </td>
                        </tr>
                        <?php else: foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <?php if ($log['full_name']): ?>
                                <div class="user-name"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($log['email']); ?></div>
                                <?php else: ?>
                                <div class="user-email">Pengguna dihapus (ID: <?php echo (int) $log['user_id']; ?>)</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-action <?php echo htmlspecialchars($log['action']); ?>">
                                    <?php echo htmlspecialchars($actionLabels[$log['action']] ?? $log['action']); ?>
                                </span>
                            </td>
                            <td><span class="desc-text"><?php echo htmlspecialchars($log['description']); ?></span></td>
                            <td><span class="ip-text"><?php echo htmlspecialchars($log['ip_address']); ?></span></td>
                            <td><span class="time-text"><?php echo formatDate($log['created_at']); ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?<?php echo buildFilterQuery(['page' => max(1, $page - 1)]); ?>" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                <a href="?<?php echo buildFilterQuery(['page' => $i]); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                <a href="?<?php echo buildFilterQuery(['page' => min($totalPages, $page + 1)]); ?>" class="page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>