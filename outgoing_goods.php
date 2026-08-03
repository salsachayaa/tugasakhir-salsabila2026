<?php
require_once 'includes/functions.php';
requireLogin();

// ========================================
// ROLE & PERMISSION CHECK
// ========================================
$currentRole = getCurrentUserRole();
$canManage = userCanManage($currentRole); // true untuk 'admin' dan 'pimpinan'
$expiring_count = getExpiryAlertCount();

$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$selected_year = $_GET['year'] ?? date('Y');
$selected_month = $_GET['month'] ?? date('m');

// Karyawan (view-only) tidak boleh membuka form create
if (!$canManage && $action === 'create') {
    header('Location: outgoing_goods.php?error=noaccess');
    exit;
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Karyawan (view-only) tidak boleh melakukan create/delete
    if (!$canManage) {
        header('Location: outgoing_goods.php?error=noaccess');
        exit;
    }

    $conn = getDBConnection();

    if ($_POST['action'] === 'create') {
        // Validasi stock tersedia
        $stmt = $conn->prepare("SELECT current_quantity, unit, price, discount, tax FROM inventory_stock WHERE id=?");
        $stmt->bind_param("i", $_POST['inventory_stock_id']);
        $stmt->execute();
        $stock = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$stock) {
            $error = '❌ Stock tidak ditemukan!';
        } elseif ($stock['current_quantity'] < $_POST['quantity']) {
            $error = '❌ Stock tidak mencukupi! Tersedia: ' . $stock['current_quantity'] . ' ' . $stock['unit'];
        } else {
            // Insert barang keluar
            $stmt = $conn->prepare("INSERT INTO outgoing_goods (user_id, inventory_stock_id, outgoing_date, spb_number, allocation_plan, part_number, item_name, quantity, unit, price, discount, tax, invoice_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssssissddss", 
                $_SESSION['user_id'],
                $_POST['inventory_stock_id'],
                $_POST['outgoing_date'],
                $_POST['spb_number'],
                $_POST['allocation_plan'],
                $_POST['part_number'],
                $_POST['item_name'],
                $_POST['quantity'],
                $_POST['unit'],
                $_POST['price'],
                $_POST['discount'],
                $_POST['tax'],
                $_POST['invoice_number'],
                $_POST['notes']
            );

            if ($stmt->execute()) {
                $outgoing_id = $conn->insert_id;
                
                // Update stock quantity
                $new_quantity = $stock['current_quantity'] - $_POST['quantity'];
                $stmt2 = $conn->prepare("UPDATE inventory_stock SET current_quantity=? WHERE id=?");
                $stmt2->bind_param("ii", $new_quantity, $_POST['inventory_stock_id']);
                $stmt2->execute();
                $stmt2->close();
                
                // Insert history
                $stmt3 = $conn->prepare("INSERT INTO stock_outgoing_history (inventory_stock_id, outgoing_goods_id, outgoing_date, spb_number, quantity, remaining_stock) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt3->bind_param("iissii", 
                    $_POST['inventory_stock_id'],
                    $outgoing_id,
                    $_POST['outgoing_date'],
                    $_POST['spb_number'],
                    $_POST['quantity'],
                    $new_quantity
                );
                $stmt3->execute();
                $stmt3->close();
                
                $success = '✅ Barang keluar berhasil ditambahkan! Stock tersisa: ' . $new_quantity . ' ' . $stock['unit'];
                $action = 'list';
            } else {
                $error = '❌ Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
    elseif ($_POST['action'] === 'delete') {
        // Get outgoing data
        $stmt = $conn->prepare("SELECT inventory_stock_id, quantity FROM outgoing_goods WHERE id=?");
        $stmt->bind_param("i", $_POST['id']);
        $stmt->execute();
        $outgoing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($outgoing) {
            // Restore stock quantity
            $stmt = $conn->prepare("UPDATE inventory_stock SET current_quantity = current_quantity + ? WHERE id=?");
            $stmt->bind_param("ii", $outgoing['quantity'], $outgoing['inventory_stock_id']);
            $stmt->execute();
            $stmt->close();
            
            // Delete from history
            $stmt = $conn->prepare("DELETE FROM stock_outgoing_history WHERE outgoing_goods_id=?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute();
            $stmt->close();
            
            // Delete outgoing
            $stmt = $conn->prepare("DELETE FROM outgoing_goods WHERE id=?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute() ? $success = '✅ Barang keluar dihapus dan stock dikembalikan!' : $error = '❌ Gagal hapus!';
            $stmt->close();
        }
    }
    $conn->close();
}

// Get available years and months
$current_year = date('Y');
$current_month = date('m');
$available_periods = [];
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT DISTINCT YEAR(outgoing_date) as year, MONTH(outgoing_date) as month FROM outgoing_goods ORDER BY year DESC, month DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $available_periods[] = ['year' => $row['year'], 'month' => $row['month']];
}
$stmt->close();

if (empty($available_periods)) {
    $available_periods[] = ['year' => $current_year, 'month' => $current_month];
}

// Validate selected period
$period_valid = false;
foreach ($available_periods as $period) {
    if ($period['year'] == $selected_year && $period['month'] == $selected_month) {
        $period_valid = true;
        break;
    }
}
if (!$period_valid && !empty($available_periods)) {
    $selected_year = $available_periods[0]['year'];
    $selected_month = $available_periods[0]['month'];
}

// Get data
$data = [];
$totals = [];
if ($action === 'list') {
    $stmt = $conn->prepare("SELECT o.*, s.location, s.item_type 
                           FROM outgoing_goods o
                           LEFT JOIN inventory_stock s ON o.inventory_stock_id = s.id
                           WHERE YEAR(o.outgoing_date)=? AND MONTH(o.outgoing_date)=?
                           ORDER BY o.outgoing_date DESC, o.spb_number");
    $stmt->bind_param("ii", $selected_year, $selected_month);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!isset($row['subtotal'])) $row['subtotal'] = $row['quantity'] * $row['price'];
        if (!isset($row['total_price'])) $row['total_price'] = $row['subtotal'] - $row['discount'] + $row['tax'];
        $data[] = $row;
        
        $key = $row['spb_number'];
        if (!isset($totals[$key])) {
            $totals[$key] = ['spb' => $row['spb_number'], 'date' => $row['outgoing_date'], 'items' => 0, 'qty' => 0, 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0];
        }
        $totals[$key]['items']++;
        $totals[$key]['qty'] += $row['quantity'];
        $totals[$key]['subtotal'] += $row['subtotal'];
        $totals[$key]['discount'] += $row['discount'];
        $totals[$key]['tax'] += $row['tax'];
        $totals[$key]['total'] += $row['total_price'];
    }
    $stmt->close();
}

// Get available stock for create form
$available_stock = [];
if ($action === 'create') {
    $stmt = $conn->prepare("SELECT id, invoice_number, part_number, item_name, current_quantity, unit, location, item_type, price, discount, tax 
                           FROM inventory_stock 
                           WHERE current_quantity > 0 
                           ORDER BY item_name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_stock[] = $row;
    }
    $stmt->close();
}

$conn->close();

function getItemTypeIcon($type) {
    switch($type) {
        case 'Sparepart': return '🔧';
        case 'Alat dan Perlengkapan': return '🛠️';
        case 'Oli, Grease, and Coolant': return '🛢️';
        default: return '📦';
    }
}

$month_names = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$selected_month = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
$displayName = $_SESSION['user_name'] ?? 'Pengguna';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barang Keluar — SKDS Logistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        .select2-container--default .select2-selection--single{height:42px;border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:4px 8px;background:#0d1117;}
        .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px;font-size:13px;color:#e5e7eb;}
        .select2-container--default .select2-selection--single .select2-selection__arrow{height:40px;}
        .select2-container--default.select2-container--focus .select2-selection--single{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,0.15);}
        .select2-dropdown{border:1px solid #10b981;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.4);background:#161b22;}
        .select2-results__option{padding:10px 12px;font-size:13px;color:#e5e7eb;}
        .select2-results__option--highlighted{background:#10b981!important;color:#fff!important;}
        .select2-search__field{background:#0d1117!important;color:#fff!important;border:1px solid rgba(255,255,255,0.1)!important;}
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    function updateStockInfo() {
        const sel = document.getElementById('stock_sel');
        const opt = sel.options[sel.selectedIndex];
        if(opt && opt.value) {
            document.getElementById('inventory_stock_id').value = opt.value;
            document.getElementById('part_number').value = opt.getAttribute('data-part');
            document.getElementById('item_name').value = opt.getAttribute('data-name');
            document.getElementById('unit').value = opt.getAttribute('data-unit');
            document.getElementById('price').value = opt.getAttribute('data-price');
            document.getElementById('discount').value = opt.getAttribute('data-discount');
            document.getElementById('tax').value = opt.getAttribute('data-tax');

            const avail = opt.getAttribute('data-qty');
            const unit = opt.getAttribute('data-unit');
            const loc = opt.getAttribute('data-location');
            const info = document.getElementById('stock_info');
            info.innerHTML = '<strong>Stock Tersedia:</strong> ' + avail + ' ' + unit +
                           ' <span style="margin-left:15px;"><strong>Lokasi:</strong> ' + loc + '</span>';
            info.style.display = 'block';

            document.getElementById('quantity').max = avail;
            document.getElementById('quantity').value = '';
            c();
        }
    }

    function c(){
        const q=+(document.getElementById('quantity')?.value)||0;
        const p=+(document.getElementById('price')?.value)||0;
        const d=+(document.getElementById('discount')?.value)||0;
        const t=+(document.getElementById('tax')?.value)||0;
        const sub=q*p;
        const tot=sub-d+t;
        const sdEl=document.getElementById('sd');
        const tdEl=document.getElementById('td');
        if(sdEl) sdEl.value='Rp '+sub.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        if(tdEl) tdEl.value='Rp '+tot.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    }

    function at(){
        const q=+(document.getElementById('quantity')?.value)||0;
        const p=+(document.getElementById('price')?.value)||0;
        const d=+(document.getElementById('discount')?.value)||0;
        const tax=(q*p-d)*0.11;
        if(document.getElementById('tax')) document.getElementById('tax').value=tax.toFixed(2);
        c();
    }

    function applyPeriodFilter() {
        const year = document.getElementById('yearFilter').value;
        const month = document.getElementById('monthFilter').value;
        window.location.href = 'outgoing_goods.php?year=' + year + '&month=' + month;
    }

    $(document).ready(function(){
        $('#stock_sel').select2({
            placeholder: 'Ketik untuk mencari barang...',
            allowClear: true,
            width: '100%',
            matcher: function(params, data) {
                if ($.trim(params.term) === '') { return data; }
                if (typeof data.text === 'undefined') { return null; }
                var searchTerm = params.term.toLowerCase();
                var optionText = data.text.toLowerCase();
                if (optionText.indexOf(searchTerm) > -1) { return data; }
                var $option = $(data.element);
                var partNumber = $option.data('part') ? $option.data('part').toString().toLowerCase() : '';
                var itemName = $option.data('name') ? $option.data('name').toString().toLowerCase() : '';
                var location = $option.data('location') ? $option.data('location').toString().toLowerCase() : '';
                if (partNumber.indexOf(searchTerm) > -1 ||
                    itemName.indexOf(searchTerm) > -1 ||
                    location.indexOf(searchTerm) > -1) { return data; }
                return null;
            }
        }).on('select2:select', function(e) {
            updateStockInfo();
        }).on('select2:clear', function(e) {
            document.getElementById('inventory_stock_id').value = '';
            document.getElementById('part_number').value = '';
            document.getElementById('item_name').value = '';
            document.getElementById('unit').value = '';
            document.getElementById('price').value = '0';
            document.getElementById('discount').value = '0';
            document.getElementById('tax').value = '0';
            document.getElementById('stock_info').style.display = 'none';
            document.getElementById('quantity').value = '';
            c();
        });

        ['quantity','price','discount','tax'].forEach(function(fieldId){
            const element = document.getElementById(fieldId);
            if(element) element.addEventListener('input', c);
        });

        if(document.getElementById('quantity')) c();
    });
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
        <a href="outgoing_goods.php" class="nav-item active">
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
            <div class="topbar-title">Barang Keluar</div>
            <div class="topbar-sub">Kelola transaksi pengeluaran barang dari gudang</div>
        </div>
    </header>

    <div class="page-body">

        <?php if($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo $error; ?></span></div>
        <?php endif; ?>

        <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo $success; ?></span></div>
        <?php endif; ?>

        <?php if($action=='create'): ?>
        <!-- ===== FORM SECTION ===== -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon"><i class="fas fa-truck-loading"></i></div>
                    <div class="panel-title">Tambah Barang Keluar</div>
                </div>
            </div>

            <?php if(empty($available_stock)): ?>
            <div class="alert" style="background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.3); color:#fbbf24; align-items:flex-start;">
                <i class="fas fa-exclamation-triangle" style="margin-top:2px;"></i>
                <div>
                    <strong style="color:#fff; display:block; margin-bottom:4px;">Tidak Ada Stock Tersedia</strong>
                    Silakan input stock terlebih dahulu di menu Inventory Stock.
                </div>
            </div>
            <div class="flex gap-3">
                <a href="inventory_stock.php?action=create_audit" class="btn-primary"><i class="fas fa-plus"></i> Input Stock</a>
                <a href="outgoing_goods.php" class="btn-secondary">Kembali</a>
            </div>
            <?php else: ?>

            <form method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="inventory_stock_id" id="inventory_stock_id">

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-box"></i> Pilih Barang dari Stock</div>
                    <div class="info-box"><i class="fas fa-info-circle"></i> Ketik nama barang, part number, atau lokasi untuk mencari.</div>
                    <div>
                        <label class="field-label">Pilih dari Stock *</label>
                        <select id="stock_sel" required style="width:100%;">
                            <option value="">-- Pilih Barang --</option>
                            <?php foreach($available_stock as $s): ?>
                            <option value="<?php echo $s['id']; ?>"
                                    data-part="<?php echo htmlspecialchars($s['part_number']); ?>"
                                    data-name="<?php echo htmlspecialchars($s['item_name']); ?>"
                                    data-qty="<?php echo $s['current_quantity']; ?>"
                                    data-unit="<?php echo $s['unit']; ?>"
                                    data-location="<?php echo htmlspecialchars($s['location']); ?>"
                                    data-price="<?php echo $s['price']; ?>"
                                    data-discount="<?php echo $s['discount']; ?>"
                                    data-tax="<?php echo $s['tax']; ?>">
                                <?php echo htmlspecialchars($s['item_name']); ?>
                                (<?php echo $s['current_quantity']; ?> <?php echo $s['unit']; ?>)
                                - <?php echo htmlspecialchars($s['part_number']); ?>
                                - <?php echo htmlspecialchars($s['location']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="stock_info" class="calc-box" style="display:none; margin-top:12px;"></div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-file-alt"></i> Informasi SPB</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label">Tgl Keluar *</label>
                            <input type="date" name="outgoing_date" required value="<?php echo date('Y-m-d'); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">No. SPB *</label>
                            <input type="text" name="spb_number" required placeholder="Surat Perintah Barang" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Rencana Alokasi *</label>
                            <input type="text" name="allocation_plan" required class="input-field">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-boxes"></i> Detail Barang</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="field-label">Part Number</label>
                            <input type="text" id="part_number" name="part_number" readonly class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Nama Barang</label>
                            <input type="text" id="item_name" name="item_name" readonly class="input-field">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="field-label">Qty Keluar *</label>
                            <input type="number" id="quantity" name="quantity" required min="1" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Satuan</label>
                            <input type="text" id="unit" name="unit" readonly class="input-field">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-money-bill-wave"></i> Harga (Rp)</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="field-label">Harga Satuan</label>
                            <input type="number" id="price" name="price" step="0.01" readonly class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Subtotal</label>
                            <input type="text" id="sd" readonly class="input-field">
                            <p class="field-hint">= Qty &times; Harga Satuan</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label">Diskon (Rp)</label>
                            <input type="number" id="discount" name="discount" step="0.01" value="0" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Pajak (Rp)</label>
                            <input type="number" id="tax" name="tax" step="0.01" value="0" class="input-field">
                            <button type="button" onclick="at()" style="background:none;border:none;color:#10b981;font-size:11px;font-weight:700;cursor:pointer;margin-top:4px;padding:0;">Auto 11%</button>
                        </div>
                        <div>
                            <label class="field-label">TOTAL</label>
                            <input type="text" id="td" readonly class="input-field" style="font-weight:700; font-size:15px;">
                            <p class="field-hint">= Subtotal - Diskon + Pajak</p>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-sticky-note"></i> Catatan</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="field-label">No Invoice / Faktur</label>
                            <input type="text" name="invoice_number" placeholder="Opsional" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Catatan</label>
                            <input type="text" name="notes" placeholder="Catatan tambahan..." class="input-field">
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary" style="flex:1;"><i class="fas fa-save"></i> Simpan Barang Keluar</button>
                    <a href="outgoing_goods.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary" style="flex:1;"><i class="fas fa-times"></i> Batal</a>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ===== LIST VIEW ===== -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon"><i class="fas fa-truck"></i></div>
                    <div class="panel-title">Barang Keluar</div>
                </div>
                <div class="filter-bar">
                    <select id="yearFilter" onchange="applyPeriodFilter()" class="select-pill">
                        <?php $years = array_unique(array_column($available_periods, 'year')); foreach($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y==$selected_year?'selected':''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="monthFilter" onchange="applyPeriodFilter()" class="select-pill">
                        <?php foreach($month_names as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num == $selected_month ? 'selected' : ''; ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="export_outgoing.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary"><i class="fas fa-download"></i> Export</a>
                    <?php if ($canManage): ?>
                    <a href="outgoing_goods.php?action=create&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-primary"><i class="fas fa-plus"></i> Tambah</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(!empty($data)):
            $total_items = count($data);
            $total_qty = array_sum(array_column($data, 'quantity'));
            $total_value = array_sum(array_column($data, 'total_price'));
            $month_key = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
            $period_display = isset($month_names[$month_key]) ? $month_names[$month_key] : 'Bulan ' . $selected_month;
        ?>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Periode</span><i class="fas fa-calendar stat-card-icon"></i></div>
                <div class="stat-card-value" style="font-size:16px;"><?php echo $period_display . ' ' . $selected_year; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Transaksi</span><i class="fas fa-receipt stat-card-icon"></i></div>
                <div class="stat-card-value"><?php echo number_format($total_items); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Qty</span><i class="fas fa-boxes stat-card-icon"></i></div>
                <div class="stat-card-value"><?php echo number_format($total_qty); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Nilai</span><i class="fas fa-money-bill-wave stat-card-icon"></i></div>
                <div class="stat-card-value" style="font-size:16px;"><?php echo formatCurrency($total_value); ?></div>
            </div>
        </div>

        <?php
        $grouped = [];
        foreach($data as $item) { $grouped[$item['spb_number']][] = $item; }
        foreach($grouped as $spb => $items):
            $t = $totals[$spb];
        ?>
        <div class="group-card">
            <div class="group-header">
                <div class="group-header-left">
                    <strong>SPB: <?php echo htmlspecialchars($t['spb']); ?></strong>
                    <span class="sep">|</span>
                    <span><?php echo date('d/m/Y', strtotime($t['date'])); ?></span>
                </div>
                <div class="group-header-right"><?php echo $t['items']; ?> Item &middot; <?php echo formatCurrency($t['total']); ?></div>
            </div>

            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th><th>Jenis</th><th>Alokasi</th><th>Part#</th><th>Nama Barang</th>
                            <th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th>
                            <th class="text-right">Diskon</th><th class="text-right">Pajak</th><th class="text-right">Total</th><th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach($items as $item): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($item['item_type']??'-'); ?></td>
                            <td><?php echo htmlspecialchars($item['allocation_plan']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($item['part_number']); ?></td>
                            <td style="font-weight:600; color:#e5e7eb;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="text-right" style="font-weight:700; color:#fbbf24;"><?php echo number_format($item['quantity']); ?> <?php echo $item['unit']; ?></td>
                            <td class="text-right"><?php echo formatCurrency($item['price']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($item['subtotal']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($item['discount']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($item['tax']); ?></td>
                            <td class="text-right" style="font-weight:700; color:#6ee7b7;"><?php echo formatCurrency($item['total_price']); ?></td>
                            <td>
                                <?php if ($canManage): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus transaksi ini?\n\nStock akan dikembalikan.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn-sm btn-delete">Hapus</button>
                                </form>
                                <?php else: ?>
                                <span style="color:#6b7280; font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="7" class="text-right">TOTAL SPB:</td>
                            <td class="text-right"><?php echo formatCurrency($t['subtotal']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($t['discount']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($t['tax']); ?></td>
                            <td class="text-right" style="font-size:14px;"><?php echo formatCurrency($t['total']); ?></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <?php else: ?>
        <div class="panel">
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>Belum Ada Data</h4>
                <p>Belum ada data untuk periode <?php
                    $month_key = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
                    echo (isset($month_names[$month_key]) ? $month_names[$month_key] : 'Bulan ' . $selected_month) . ' ' . $selected_year;
                ?></p>
                <?php if ($canManage): ?>
                <a href="outgoing_goods.php?action=create&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-primary"><i class="fas fa-plus"></i> Tambah Barang Keluar</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

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