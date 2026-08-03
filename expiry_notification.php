<?php
require_once 'includes/functions.php';
requireLogin();

// ========================================
// ROLE & PERMISSION CHECK
// ========================================
$currentRole = getCurrentUserRole();
$canManage = userCanManage($currentRole); // true untuk 'admin' dan 'pimpinan'
$expiring_count = getExpiryAlertCount();

$warningDays = 30;
$filter_status = $_GET['status'] ?? 'all'; // all | expired | warning
$search = $_GET['search'] ?? '';

$allItems = getExpiringStockItems($warningDays);

// Filter by status
$items = array_filter($allItems, function ($item) use ($filter_status) {
    if ($filter_status === 'all') return true;
    if ($filter_status === 'expired') return $item['expiry_info']['status'] === 'EXPIRED';
    if ($filter_status === 'warning') return $item['expiry_info']['status'] === 'WARNING';
    return true;
});

// Filter by search
if (!empty($search)) {
    $items = array_filter($items, function ($item) use ($search) {
        $needle = strtolower($search);
        return strpos(strtolower($item['item_name']), $needle) !== false
            || strpos(strtolower($item['part_number']), $needle) !== false
            || strpos(strtolower($item['location']), $needle) !== false;
    });
}
$items = array_values($items);

$summary = ['expired' => 0, 'warning' => 0];
foreach ($allItems as $item) {
    if ($item['expiry_info']['status'] === 'EXPIRED') $summary['expired']++;
    elseif ($item['expiry_info']['status'] === 'WARNING') $summary['warning']++;
}

$displayName = $_SESSION['user_name'] ?? 'Pengguna';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi Kadaluarsa — SKDS Logistik</title>
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

        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 24px; }
        .stat-card { background: #161b22; border: 1px solid rgba(255,255,255,0.07); border-radius: 14px; padding: 20px; }
        .stat-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .stat-card-label { color: #6b7280; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .stat-card-icon { font-size: 18px; }
        .stat-card-value { color: #fff; font-size: 26px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; }

        .filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 20px; }
        .select-pill { background: #0d1117; border: 1px solid rgba(255,255,255,0.1); color: #d1d5db; border-radius: 9px; padding: 9px 14px; font-size: 13px; font-weight: 500; }
        .select-pill:focus { outline: none; border-color: #10b981; }
        .input-field { width: 100%; background: #0d1117; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 10px 14px; color: #e5e7eb; font-size: 13.5px; }
        .input-field:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }

        .filter-chip { background: rgba(255,255,255,0.04); color: #9ca3af; border: 1px solid rgba(255,255,255,0.08); border-radius: 9px; padding: 8px 16px; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
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

        .badge-pill { font-size: 10.5px; font-weight: 700; padding: 3px 10px; border-radius: 99px; display: inline-flex; align-items: center; gap: 4px; }
        .badge-aman { background: rgba(16,185,129,0.15); color: #6ee7b7; }
        .badge-sedang { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-kritis { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-location { background: rgba(6,182,212,0.12); color: #67e8f9; border: 1px solid rgba(6,182,212,0.25); font-size: 10.5px; font-weight: 600; padding: 3px 10px; border-radius: 99px; }

        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 40px; color: #374151; margin-bottom: 14px; }
        .empty-state h4 { color: #9ca3af; font-size: 15px; font-weight: 600; margin: 0 0 6px; }
        .empty-state p { color: #4b5563; font-size: 13px; margin: 0 0 18px; }

        .sidebar-toggle { display: none; position: fixed; top: 16px; left: 16px; z-index: 50; background: #065f46; border: none; color: #fff; width: 40px; height: 40px; border-radius: 10px; font-size: 16px; cursor: pointer; }
        .sidebar-overlay { display: none; }

        @media (max-width: 1024px) { .stat-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-toggle { display: flex; align-items: center; justify-content: center; }
            .sidebar-overlay { display: block; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 35; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
            .sidebar-overlay.open { opacity: 1; pointer-events: all; }
            .main-content { margin-left: 0; }
            .page-body { padding: 20px 16px; }
            .topbar { padding: 0 16px 0 64px; }
        }
    </style>
    <script>
    function applyFilter() {
        const status = document.getElementById('filterStatus')?.value || 'all';
        const search = document.getElementById('searchBox')?.value || '';
        let url = 'expiry_notification.php?status=' + status;
        if (search) url += '&search=' + encodeURIComponent(search);
        window.location.href = url;
    }
    </script>
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
        <a href="expiry_notification.php" class="nav-item active">
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
        <a href="profile.php" class="nav-item ">
            <i class="fas fa-user-circle"></i> Profil Saya
        </a>
        <?php if ($currentRole === 'pimpinan'): ?>
        <a href="manage_users.php" class="nav-item ">
            <i class="fas fa-users-cog"></i> Kelola Pengguna
        </a>
        <?php endif; ?>
        <?php if ($canManage): ?>
        <a href="activity_log.php" class="nav-item ">
            <i class="fas fa-clock-rotate-left"></i> Riwayat Aktivitas
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
            <div class="topbar-title">Notifikasi Kadaluarsa</div>
            <div class="topbar-sub">Barang yang sudah atau akan kadaluarsa dalam <?php echo $warningDays; ?> hari</div>
        </div>
    </header>

    <div class="page-body">

        <div class="stat-grid">
            <div class="stat-card" style="border-color: rgba(239,68,68,0.3);">
                <div class="stat-card-head">
                    <span class="stat-card-label">Sudah Kadaluarsa</span>
                    <i class="fas fa-circle-exclamation stat-card-icon" style="color:#f87171;"></i>
                </div>
                <div class="stat-card-value" style="color:#f87171;"><?php echo number_format($summary['expired']); ?></div>
            </div>
            <div class="stat-card" style="border-color: rgba(245,158,11,0.3);">
                <div class="stat-card-head">
                    <span class="stat-card-label">Akan Kadaluarsa (H-<?php echo $warningDays; ?>)</span>
                    <i class="fas fa-triangle-exclamation stat-card-icon" style="color:#fbbf24;"></i>
                </div>
                <div class="stat-card-value" style="color:#fbbf24;"><?php echo number_format($summary['warning']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head">
                    <span class="stat-card-label">Total Item Terpantau</span>
                    <i class="fas fa-box stat-card-icon"></i>
                </div>
                <div class="stat-card-value"><?php echo number_format($summary['expired'] + $summary['warning']); ?></div>
            </div>
        </div>

        <div class="panel" style="margin-bottom: 24px;">
            <div class="filter-bar">
                <a href="expiry_notification.php?status=all<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="filter-chip <?php echo $filter_status=='all'?'active':''; ?>">Semua</a>
                <a href="expiry_notification.php?status=expired<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="filter-chip <?php echo $filter_status=='expired'?'active':''; ?>">Kadaluarsa</a>
                <a href="expiry_notification.php?status=warning<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="filter-chip <?php echo $filter_status=='warning'?'active':''; ?>">Akan Kadaluarsa</a>
                <input type="text" id="searchBox" placeholder="Cari nama, part number, lokasi..." value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.keyCode==13)applyFilter();" class="input-field" style="flex:1; min-width:200px; max-width:320px;">
                <button onclick="applyFilter()" class="filter-chip"><i class="fas fa-search"></i> Cari</button>
                <?php if (!empty($search) || $filter_status !== 'all'): ?>
                <a href="expiry_notification.php" class="filter-chip"><i class="fas fa-redo"></i> Reset</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($items)): ?>
        <div class="panel" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th><th>Nama Barang</th><th>Part#</th><th>Lokasi</th>
                            <th class="text-right">Sisa Stock</th><th>Tanggal Kadaluarsa</th><th class="text-center">Status</th><th>Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($items as $item): $info = $item['expiry_info']; ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td style="font-weight:600; color:#e5e7eb;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($item['part_number']); ?></td>
                            <td><span class="badge-location"><?php echo htmlspecialchars($item['location']); ?></span></td>
                            <td class="text-right" style="font-weight:700; color:#6ee7b7;"><?php echo number_format($item['current_quantity']); ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($item['expiry_date'])); ?></td>
                            <td class="text-center"><span class="badge-pill <?php echo $info['class']; ?>"><?php echo htmlspecialchars($info['status'] === 'EXPIRED' ? 'KADALUARSA' : 'H-' . $info['days']); ?></span></td>
                            <td style="color:#9ca3af; font-size:11.5px;"><?php echo htmlspecialchars($item['invoice_number']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="panel">
            <div class="empty-state">
                <i class="fas fa-circle-check" style="color:#10b981;"></i>
                <h4>Tidak Ada Barang Kadaluarsa</h4>
                <p>Tidak ada barang yang kadaluarsa atau akan kadaluarsa dalam <?php echo $warningDays; ?> hari ke depan.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel" style="margin-top:20px; background: rgba(6,182,212,0.05); border-color: rgba(6,182,212,0.2);">
            <p style="color:#67e8f9; font-size:12.5px; margin:0; display:flex; align-items:flex-start; gap:10px;">
                <i class="fas fa-circle-info" style="margin-top:2px;"></i>
                <span>Notifikasi email otomatis dikirim ke Admin & Pimpinan saat barang mencapai H-<?php echo $warningDays; ?> dan saat kadaluarsa, dijalankan otomatis setiap hari via sistem.</span>
            </p>
        </div>

    </div><!-- /page-body -->
</div><!-- /main-content -->

<script>
const toggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
toggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
</script>
</body>
</html>