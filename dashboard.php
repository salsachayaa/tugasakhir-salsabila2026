<?php
session_start();
require_once 'includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'] ?? null;
$user = $userId ? getUserById($userId) : null;
$conn = getDBConnection();
$current_year = date('Y');

$currentRole = getCurrentUserRole();
$canManage = userCanManage($currentRole); // true untuk 'admin' dan 'pimpinan'
$expiring_count = getExpiryAlertCount();

// ========================================
// STATISTIK BARANG MASUK - FIXED
// ========================================
$stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM((quantity * price) - discount + tax) AS total_value FROM incoming_goods");
$stmt->execute();
$incoming_all = $stmt->get_result()->fetch_assoc();
$total_incoming = $incoming_all['total'] ?? 0;
$total_incoming_value = $incoming_all['total_value'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM incoming_goods WHERE YEAR(invoice_date) = ?");
$stmt->bind_param("i", $current_year);
$stmt->execute();
$total_incoming_this_year = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

// ========================================
// STATISTIK INVENTORY STOCK
// ========================================
$stmt = $conn->prepare("SELECT COUNT(*) as total_items, SUM(current_quantity) as total_current_qty, SUM((initial_quantity * price) - discount + tax) as total_value FROM inventory_stock");
$stmt->execute();
$stock_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as critical_count FROM inventory_stock WHERE initial_quantity > 0 AND (current_quantity / initial_quantity) < 0.3");
$stmt->execute();
$critical_stock = $stmt->get_result()->fetch_assoc()['critical_count'] ?? 0;
$stmt->close();

// ========================================
// STATISTIK NOTIFIKASI KADALUARSA
// ========================================
$expiry_summary = getExpiryNotificationSummary(30);

// ========================================
// STATISTIK BARANG KELUAR
// ========================================
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM outgoing_goods");
$stmt->execute();
$total_outgoing = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM outgoing_goods WHERE YEAR(outgoing_date) = ?");
$stmt->bind_param("i", $current_year);
$stmt->execute();
$total_outgoing_this_year = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

// ========================================
// RECENT ACTIVITY
// ========================================
$stmt = $conn->prepare("
    (SELECT 
        'IN' as type, 
        invoice_date as date, 
        invoice_number as doc_number, 
        item_name, 
        quantity, 
        unit, 
        ((quantity * price) - discount + tax) as total_price, 
        created_at 
    FROM incoming_goods) 
    UNION ALL 
    (SELECT 
        'OUT' as type, 
        outgoing_date as date, 
        spb_number as doc_number, 
        item_name, 
        quantity, 
        unit, 
        ((quantity * price) - discount + tax) as total_price, 
        created_at 
    FROM outgoing_goods) 
    ORDER BY created_at DESC 
    LIMIT 6
");
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ========================================
// TREND 6 BULAN TERAKHIR
// ========================================
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(invoice_date, '%Y-%m') as month, 
        COUNT(*) as total_items 
    FROM incoming_goods 
    WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m') 
    ORDER BY month ASC
");
$stmt->execute();
$trend_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

$displayName = is_array($user) && !empty($user['full_name']) ? $user['full_name'] : ($_SESSION['user_name'] ?? 'Pengguna');
$currentPage = 'dashboard';

function formatMonthID($yearMonth) {
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    list($year, $month) = explode('-', $yearMonth);
    return $months[(int)$month - 1] . ' ' . $year;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Sistem Manajemen Logistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; margin: 0; }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(16,185,129,0.4); border-radius: 99px; }

        /* Sidebar */
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: linear-gradient(180deg, #052e16 0%, #064e3b 60%, #065f46 100%);
            border-right: 1px solid rgba(16,185,129,0.15);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 40;
            transition: transform 0.3s ease;
        }
        .sidebar-logo {
            padding: 28px 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-logo img { width: 40px; height: 40px; object-fit: contain; border-radius: 10px; }
        .sidebar-logo-text { font-family: 'Plus Jakarta Sans', sans-serif; }
        .sidebar-logo-text h1 { color: #fff; font-size: 14px; font-weight: 700; letter-spacing: 0.02em; margin: 0; line-height: 1.2; }
        .sidebar-logo-text p { color: #6ee7b7; font-size: 10px; font-weight: 500; margin: 0; letter-spacing: 0.05em; text-transform: uppercase; }

        .sidebar-section { padding: 20px 16px 8px; }
        .sidebar-section-label {
            color: rgba(110,231,183,0.5);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 0 8px;
            margin-bottom: 6px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            color: rgba(209,250,229,0.7);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 2px;
            position: relative;
        }
        .nav-item:hover {
            background: rgba(16,185,129,0.12);
            color: #d1fae5;
        }
        .nav-item.active {
            background: linear-gradient(135deg, rgba(16,185,129,0.25), rgba(5,150,105,0.15));
            color: #fff;
            border: 1px solid rgba(16,185,129,0.25);
        }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 50%;
            transform: translateY(-50%);
            width: 3px; height: 60%;
            background: #10b981;
            border-radius: 0 4px 4px 0;
        }
        .nav-item i { width: 18px; text-align: center; font-size: 14px; }
        .nav-item .badge {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 99px;
            min-width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.07);
        }
        .user-card {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-avatar {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: #fff; font-weight: 700;
            flex-shrink: 0;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-info .name { color: #fff; font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-info .role { color: #6ee7b7; font-size: 10.5px; }
        .logout-btn {
            color: rgba(209,250,229,0.5);
            font-size: 14px;
            transition: color 0.2s;
            text-decoration: none;
            flex-shrink: 0;
        }
        .logout-btn:hover { color: #f87171; }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            background: #0d1117;
        }

        /* Topbar */
        .topbar {
            background: rgba(13,17,23,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky; top: 0; z-index: 30;
        }
        .topbar-title { font-family: 'Plus Jakarta Sans', sans-serif; color: #fff; font-size: 18px; font-weight: 700; }
        .topbar-sub { color: #4b5563; font-size: 12px; margin-top: 1px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-badge {
            background: rgba(239,68,68,0.15);
            border: 1px solid rgba(239,68,68,0.3);
            color: #f87171;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            display: flex; align-items: center; gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .topbar-badge:hover { background: rgba(239,68,68,0.25); }
        .topbar-time {
            color: #6b7280;
            font-size: 12px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            padding: 6px 14px;
            border-radius: 8px;
        }

        /* Page content */
        .page-body { padding: 28px 32px; }

        /* Stat Cards */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card {
            background: #161b22;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: border-color 0.2s, transform 0.2s;
        }
        .stat-card:hover { border-color: rgba(16,185,129,0.3); transform: translateY(-2px); }
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 80px; height: 80px;
            border-radius: 0 16px 0 80px;
            opacity: 0.08;
        }
        .stat-card.green::after { background: #10b981; }
        .stat-card.teal::after { background: #14b8a6; }
        .stat-card.cyan::after { background: #06b6d4; }
        .stat-card.amber::after { background: #f59e0b; }

        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            margin-bottom: 16px;
        }
        .stat-icon.green { background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.2); }
        .stat-icon.teal { background: rgba(20,184,166,0.15); color: #14b8a6; border: 1px solid rgba(20,184,166,0.2); }
        .stat-icon.cyan { background: rgba(6,182,212,0.15); color: #06b6d4; border: 1px solid rgba(6,182,212,0.2); }
        .stat-icon.amber { background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }

        .stat-label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px; }
        .stat-value { color: #fff; font-size: 30px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; line-height: 1; margin-bottom: 10px; }
        .stat-value.sm { font-size: 20px; }
        .stat-sub { color: #4b5563; font-size: 12px; }
        .stat-sub strong { color: #9ca3af; }

        /* Quick Actions */
        .action-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
        .action-card {
            background: #161b22;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            padding: 18px 20px;
            display: flex; align-items: center; gap: 14px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .action-card:hover { border-color: rgba(16,185,129,0.35); background: #1c2230; transform: translateY(-1px); }
        .action-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .action-icon.green { background: rgba(16,185,129,0.15); color: #10b981; }
        .action-icon.teal { background: rgba(20,184,166,0.15); color: #14b8a6; }
        .action-icon.cyan { background: rgba(6,182,212,0.15); color: #06b6d4; }
        .action-icon.amber { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .action-title { color: #e5e7eb; font-size: 13px; font-weight: 600; margin-bottom: 2px; }
        .action-sub { color: #4b5563; font-size: 11.5px; }

        /* Bottom grid */
        .bottom-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .panel {
            background: #161b22;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 16px;
            padding: 24px;
        }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .panel-title { color: #fff; font-size: 15px; font-weight: 600; }
        .panel-badge { color: #6ee7b7; font-size: 12px; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); padding: 4px 10px; border-radius: 99px; }
        .panel-link { color: #10b981; font-size: 12px; font-weight: 600; text-decoration: none; transition: color 0.2s; }
        .panel-link:hover { color: #6ee7b7; }

        /* Activity items */
        .activity-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        .activity-item:last-child { border-bottom: none; padding-bottom: 0; }
        .activity-dot {
            width: 32px; height: 32px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .activity-dot.in { background: rgba(16,185,129,0.15); color: #10b981; }
        .activity-dot.out { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .activity-info { flex: 1; min-width: 0; }
        .activity-name { color: #e5e7eb; font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .activity-meta { color: #4b5563; font-size: 11px; margin-top: 3px; }
        .activity-tag {
            font-size: 10px; font-weight: 700;
            padding: 2px 8px; border-radius: 6px;
            flex-shrink: 0;
        }
        .activity-tag.in { background: rgba(16,185,129,0.15); color: #10b981; }
        .activity-tag.out { background: rgba(245,158,11,0.15); color: #f59e0b; }

        /* Alert */
        .alert-critical {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.25);
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .alert-icon { font-size: 20px; flex-shrink: 0; }
        .alert-text strong { color: #fca5a5; font-size: 13px; font-weight: 600; display: block; }
        .alert-text span { color: #9ca3af; font-size: 12px; }

        /* Empty state */
        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 0; text-align: center; }
        .empty-state i { font-size: 36px; color: #374151; margin-bottom: 12px; }
        .empty-state h4 { color: #6b7280; font-size: 13px; font-weight: 600; margin: 0 0 4px; }
        .empty-state p { color: #374151; font-size: 12px; margin: 0; }

        /* Mobile toggle */
        .sidebar-toggle {
            display: none;
            position: fixed; top: 16px; left: 16px;
            z-index: 50;
            background: #065f46;
            border: none; color: #fff;
            width: 40px; height: 40px;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
        }
        .sidebar-overlay { display: none; }

        @media (max-width: 1024px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .action-grid { grid-template-columns: repeat(2, 1fr); }
            .bottom-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-toggle { display: flex; align-items: center; justify-content: center; }
            .sidebar-overlay { display: block; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 35; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
            .sidebar-overlay.open { opacity: 1; pointer-events: all; }
            .main-content { margin-left: 0; }
            .page-body { padding: 20px 16px; }
            .topbar { padding: 0 16px 0 64px; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .action-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .stat-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<!-- Sidebar Toggle (Mobile) -->
<button class="sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ========== SIDEBAR ========== -->
<aside class="sidebar" id="sidebar">
    <!-- Logo -->
    <div class="sidebar-logo">
        <img src="images/logo-skds.jpeg" alt="Logo SKDS">
        <div class="sidebar-logo-text">
            <h1>SKDS Logistik</h1>
            <p>PT. Sarana Karya Dua Satu</p>
        </div>
    </div>

    <!-- Nav -->
    <div class="sidebar-section" style="flex: 1; overflow-y: auto;">
        <div class="sidebar-section-label">Menu Utama</div>

        <a href="dashboard.php" class="nav-item active">
            <i class="fas fa-gauge-high"></i>
            Dashboard
        </a>
        <a href="incoming_goods.php" class="nav-item">
            <i class="fas fa-boxes-stacking"></i>
            Barang Masuk
        </a>
        <a href="inventory_stock.php" class="nav-item">
            <i class="fas fa-warehouse"></i>
            Inventory Stock
            <?php if($critical_stock > 0): ?>
            <span class="badge"><?php echo $critical_stock; ?></span>
            <?php endif; ?>
        </a>
        <a href="outgoing_goods.php" class="nav-item">
            <i class="fas fa-truck-fast"></i>
            Barang Keluar
        </a>
        <a href="expiry_notification.php" class="nav-item ">
            <i class="fas fa-triangle-exclamation"></i> Notifikasi Kadaluarsa
            <?php if ($expiring_count > 0): ?><span class="badge"><?php echo $expiring_count; ?></span><?php endif; ?>
        </a>

        <div class="sidebar-section-label" style="margin-top: 20px;">Laporan & Aksi</div>
        <?php if ($canManage): ?>
        <a href="incoming_goods.php?action=create" class="nav-item">
            <i class="fas fa-plus-circle"></i>
            Tambah Barang
        </a>
        <a href="outgoing_goods.php?action=create" class="nav-item">
            <i class="fas fa-truck-loading"></i>
            Proses Keluar
        </a>
        <?php endif; ?>
        <a href="export_incoming.php?year=<?php echo $current_year; ?>" class="nav-item">
            <i class="fas fa-file-arrow-down"></i>
            Export Laporan
        </a>

        <div class="sidebar-section-label" style="margin-top: 20px;">Akun</div>
        <a href="profile.php" class="nav-item">
            <i class="fas fa-user-circle"></i>
            Profil Saya
        </a>
        <?php if ($currentRole === 'pimpinan'): ?>
        <a href="manage_users.php" class="nav-item">
            <i class="fas fa-users-cog"></i>
            Kelola Pengguna
        </a>
        <?php endif; ?>
        <?php if ($canManage): ?>
        <a href="activity_log.php" class="nav-item">
            <i class="fas fa-clock-rotate-left"></i>
            Riwayat Aktivitas
        </a>
        <?php endif; ?>
    </div>

    <!-- User Footer -->
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="role"><?php echo htmlspecialchars(getRoleLabel($currentRole)); ?></div>
            </div>
            <a href="logout.php" class="logout-btn" title="Logout">
                <i class="fas fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<!-- ========== MAIN CONTENT ========== -->
<div class="main-content">

    <!-- Topbar -->
    <header class="topbar">
        <div>
            <div class="topbar-title">Dashboard</div>
            <div class="topbar-sub"><?php echo date('l, d F Y'); ?></div>
        </div>
        <div class="topbar-right">
            <?php if($critical_stock > 0): ?>
            <a href="inventory_stock.php" class="topbar-badge">
                <i class="fas fa-triangle-exclamation"></i>
                <?php echo $critical_stock; ?> Stok Kritis
            </a>
            <?php endif; ?>
            <div class="topbar-time">
                <i class="fas fa-clock" style="margin-right: 6px; color: #374151;"></i>
                <span id="clockTime"></span>
            </div>
        </div>
    </header>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Alert Stock Kritis -->
        <?php if($critical_stock > 0): ?>
        <div class="alert-critical">
            <div class="alert-icon">⚠️</div>
            <div class="alert-text">
                <strong>Peringatan: Stok Kritis Terdeteksi</strong>
                <span>Terdapat <strong style="color:#fca5a5;"><?php echo $critical_stock; ?> item</strong> dengan stok di bawah 30% dari jumlah awal. Segera lakukan pemesanan ulang.</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alert Barang Kadaluarsa -->
        <?php if($expiry_summary['total'] > 0): ?>
        <div class="alert-critical">
            <div class="alert-icon">⏰</div>
            <div class="alert-text">
                <strong>Peringatan: Barang Kadaluarsa</strong>
                <span>
                    <?php if ($expiry_summary['expired'] > 0): ?>
                        <strong style="color:#fca5a5;"><?php echo $expiry_summary['expired']; ?> item</strong> sudah <strong style="color:#fca5a5;">kadaluarsa</strong><?php echo $expiry_summary['warning'] > 0 ? ', dan ' : '. '; ?>
                    <?php endif; ?>
                    <?php if ($expiry_summary['warning'] > 0): ?>
                        <strong style="color:#fbbf24;"><?php echo $expiry_summary['warning']; ?> item</strong> akan kadaluarsa dalam 30 hari.
                    <?php endif; ?>
                    <a href="expiry_notification.php" style="color:#67e8f9; text-decoration:underline; margin-left:4px;">Lihat detail &rarr;</a>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="stat-grid">
            <div class="stat-card green">
                <div class="stat-icon green"><i class="fas fa-boxes-stacking"></i></div>
                <div class="stat-label">Barang Masuk</div>
                <div class="stat-value"><?php echo number_format($total_incoming); ?></div>
                <div class="stat-sub"><strong><?php echo number_format($total_incoming_this_year); ?></strong> transaksi tahun ini</div>
            </div>
            <div class="stat-card teal">
                <div class="stat-icon teal"><i class="fas fa-warehouse"></i></div>
                <div class="stat-label">Inventory Stock</div>
                <div class="stat-value"><?php echo number_format($stock_stats['total_items'] ?? 0); ?></div>
                <div class="stat-sub">Sisa: <strong><?php echo number_format($stock_stats['total_current_qty'] ?? 0); ?></strong> unit</div>
            </div>
            <div class="stat-card cyan">
                <div class="stat-icon cyan"><i class="fas fa-truck-fast"></i></div>
                <div class="stat-label">Barang Keluar</div>
                <div class="stat-value"><?php echo number_format($total_outgoing); ?></div>
                <div class="stat-sub"><strong><?php echo number_format($total_outgoing_this_year); ?></strong> transaksi tahun ini</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-icon amber"><i class="fas fa-coins"></i></div>
                <div class="stat-label">Nilai Inventori</div>
                <div class="stat-value sm"><?php echo formatCurrency($stock_stats['total_value'] ?? 0); ?></div>
                <div class="stat-sub">Total nilai aset saat ini</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="action-grid">
            <?php if ($canManage): ?>
            <a href="incoming_goods.php?action=create" class="action-card">
                <div class="action-icon green"><i class="fas fa-plus-circle"></i></div>
                <div>
                    <div class="action-title">Tambah Barang</div>
                    <div class="action-sub">Input data barang masuk</div>
                </div>
            </a>
            <a href="outgoing_goods.php?action=create" class="action-card">
                <div class="action-icon teal"><i class="fas fa-truck-loading"></i></div>
                <div>
                    <div class="action-title">Proses Keluar</div>
                    <div class="action-sub">Buat SPB pengiriman</div>
                </div>
            </a>
            <?php endif; ?>
            <a href="inventory_stock.php" class="action-card">
                <div class="action-icon cyan"><i class="fas fa-clipboard-list"></i></div>
                <div>
                    <div class="action-title">Cek Stok</div>
                    <div class="action-sub">Lihat kondisi inventory</div>
                </div>
            </a>
            <a href="export_incoming.php?year=<?php echo $current_year; ?>" class="action-card">
                <div class="action-icon amber"><i class="fas fa-file-arrow-down"></i></div>
                <div>
                    <div class="action-title">Export Laporan</div>
                    <div class="action-sub">Unduh data Excel</div>
                </div>
            </a>
        </div>

        <!-- Bottom: Chart + Activity -->
        <div class="bottom-grid">

            <!-- Trend Chart -->
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Tren Barang Masuk</span>
                    <span class="panel-badge">6 Bulan Terakhir</span>
                </div>
                <?php if(!empty($trend_data)): ?>
                <div style="height: 280px;">
                    <canvas id="trendChart"></canvas>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <h4>Belum Ada Data</h4>
                    <p>Tren akan tampil setelah ada transaksi</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activity -->
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Aktivitas Terbaru</span>
                    <a href="incoming_goods.php" class="panel-link">Lihat semua <i class="fas fa-arrow-right" style="font-size: 11px;"></i></a>
                </div>
                <?php if(!empty($recent_activities)): ?>
                <?php foreach($recent_activities as $a): $isIn = $a['type']==='IN'; ?>
                <div class="activity-item">
                    <div class="activity-dot <?php echo $isIn?'in':'out'; ?>">
                        <i class="fas <?php echo $isIn?'fa-arrow-down':'fa-arrow-up'; ?>"></i>
                    </div>
                    <div class="activity-info">
                        <div class="activity-name"><?php echo htmlspecialchars($a['item_name']); ?></div>
                        <div class="activity-meta">
                            <?php echo htmlspecialchars($a['doc_number']); ?> &middot;
                            <?php echo number_format($a['quantity']); ?> <?php echo $a['unit']; ?> &middot;
                            <?php echo date('d/m/Y', strtotime($a['date'])); ?>
                        </div>
                    </div>
                    <span class="activity-tag <?php echo $isIn?'in':'out'; ?>">
                        <?php echo $isIn?'MASUK':'KELUAR'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4>Belum Ada Aktivitas</h4>
                    <p>Aktivitas transaksi akan tampil di sini</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div><!-- /page-body -->
</div><!-- /main-content -->

<!-- Chart Script -->
<?php if(!empty($trend_data)): ?>
<script>
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: [<?php foreach($trend_data as $td): ?>'<?php echo formatMonthID($td['month']); ?>',<?php endforeach; ?>],
        datasets: [{
            label: 'Transaksi',
            data: [<?php foreach($trend_data as $td): ?><?php echo $td['total_items']; ?>,<?php endforeach; ?>],
            backgroundColor: function(ctx) {
                const chart = ctx.chart;
                const {ctx: c, chartArea} = chart;
                if(!chartArea) return 'rgba(16,185,129,0.7)';
                const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                gradient.addColorStop(0, 'rgba(16,185,129,0.8)');
                gradient.addColorStop(1, 'rgba(5,150,105,0.2)');
                return gradient;
            },
            borderColor: '#10b981',
            borderWidth: 1,
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1c2230',
                padding: 12,
                titleColor: '#9ca3af',
                bodyColor: '#10b981',
                bodyFont: { size: 16, weight: '700' },
                borderColor: 'rgba(255,255,255,0.08)',
                borderWidth: 1,
                callbacks: {
                    label: ctx => ' ' + ctx.parsed.y + ' transaksi'
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: '#4b5563', font: { size: 11 }, stepSize: 1 },
                grid: { color: 'rgba(255,255,255,0.04)' },
                border: { color: 'transparent' }
            },
            x: {
                ticks: { color: '#4b5563', font: { size: 11 } },
                grid: { display: false },
                border: { color: 'transparent' }
            }
        }
    }
});
</script>
<?php endif; ?>

<script>
// Jam real-time
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    const el = document.getElementById('clockTime');
    if(el) el.textContent = h + ':' + m + ':' + s;
}
updateClock();
setInterval(updateClock, 1000);

// Sidebar toggle mobile
const toggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

toggle.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
});
overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
});
</script>
</body>
</html>