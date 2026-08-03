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

// Karyawan (view-only) tidak boleh membuka form create_audit/edit
if (!$canManage && in_array($action, ['create_audit', 'edit'])) {
    header('Location: inventory_stock.php?error=noaccess');
    exit;
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Karyawan (view-only) tidak boleh melakukan create/update/delete
    if (!$canManage) {
        header('Location: inventory_stock.php?error=noaccess');
        exit;
    }

    $conn = getDBConnection();

    if ($_POST['action'] === 'create_audit') {
        $user_id = (int)$_SESSION['user_id'];
        $invoice_date = $_POST['invoice_date'];
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $invoice_number = $_POST['invoice_number'];
        $vendor = $_POST['vendor'];
        $allocation_plan = $_POST['allocation_plan'];
        $project = $_POST['project'];
        $item_type = $_POST['item_type'];
        $part_number = $_POST['part_number'];
        $item_name = $_POST['item_name'];
        $location = $_POST['location'];
        $quantity = (int)$_POST['quantity'];
        $current_quantity = (int)$_POST['quantity'];
        $unit = $_POST['unit'];
        $price = (float)$_POST['price'];
        $discount = (float)($_POST['discount'] ?? 0);
        $tax = (float)($_POST['tax'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO inventory_stock (user_id, invoice_date, expiry_date, invoice_number, vendor, allocation_plan, project, item_type, part_number, item_name, location, initial_quantity, current_quantity, unit, price, discount, tax, stock_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'AUDIT', ?)");
        
        $stmt->bind_param("issssssssssiisddds", 
            $user_id, $invoice_date, $expiry_date, $invoice_number, $vendor, $allocation_plan,
            $project, $item_type, $part_number, $item_name, $location,
            $quantity, $current_quantity, $unit, $price, $discount, $tax, $notes
        );

        if ($stmt->execute()) {
            $success = 'Stock audit berhasil ditambahkan!';
            $action = 'list';
        } else {
            $error = 'Error: ' . $stmt->error;
        }
        $stmt->close();
    }
    elseif ($_POST['action'] === 'update') {
        $invoice_date = $_POST['invoice_date'];
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $invoice_number = $_POST['invoice_number'];
        $vendor = $_POST['vendor'];
        $allocation_plan = $_POST['allocation_plan'];
        $project = $_POST['project'];
        $item_type = $_POST['item_type'];
        $part_number = $_POST['part_number'];
        $item_name = $_POST['item_name'];
        $location = $_POST['location'];
        $initial_quantity = (int)$_POST['initial_quantity'];
        $current_quantity = (int)$_POST['current_quantity'];
        $unit = $_POST['unit'];
        $price = (float)$_POST['price'];
        $discount = (float)($_POST['discount'] ?? 0);
        $tax = (float)($_POST['tax'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        $id = (int)$_POST['id'];
        
        $stmt = $conn->prepare("UPDATE inventory_stock SET invoice_date=?, expiry_date=?, invoice_number=?, vendor=?, allocation_plan=?, project=?, item_type=?, part_number=?, item_name=?, location=?, initial_quantity=?, current_quantity=?, unit=?, price=?, discount=?, tax=?, notes=? WHERE id=?");
        
        $stmt->bind_param("ssssssssssiisdddsi",
            $invoice_date, $expiry_date, $invoice_number, $vendor, $allocation_plan, $project,
            $item_type, $part_number, $item_name, $location, $initial_quantity,
            $current_quantity, $unit, $price, $discount, $tax, $notes, $id
        );

        if ($stmt->execute()) {
            $success = 'Stock berhasil diupdate!';
            $action = 'list';
        } else {
            $error = 'Gagal update!';
        }
        $stmt->close();
    }
    elseif ($_POST['action'] === 'delete') {
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM outgoing_goods WHERE inventory_stock_id=?");
        $check_stmt->bind_param("i", $_POST['id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($check_result['count'] > 0) {
            $error = 'Stock tidak bisa dihapus karena sudah ada barang keluar!';
        } else {
            $stmt = $conn->prepare("DELETE FROM inventory_stock WHERE id=?");
            $stmt->bind_param("i", $_POST['id']);
            $stmt->execute() ? $success = 'Stock dihapus!' : $error = 'Gagal hapus!';
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
$stmt = $conn->prepare("SELECT DISTINCT YEAR(invoice_date) as year, MONTH(invoice_date) as month FROM inventory_stock ORDER BY year DESC, month DESC");
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

// Get data for list view
$data = [];
$locations = [];
$types_list = [];
if ($action === 'list') {
    $filter_type = $_GET['type'] ?? 'all';
    $filter_location = $_GET['location'] ?? 'all';
    $search = $_GET['search'] ?? '';
    
    $query = "SELECT s.*, (SELECT COUNT(*) FROM stock_outgoing_history WHERE inventory_stock_id = s.id) as outgoing_count FROM inventory_stock s WHERE YEAR(s.invoice_date)=? AND MONTH(s.invoice_date)=?";
    $params = [$selected_year, $selected_month];
    $types = "ii";
    
    if ($filter_type !== 'all') {
        $query .= " AND s.item_type=?";
        $params[] = $filter_type;
        $types .= "s";
    }
    
    if ($filter_location !== 'all') {
        $query .= " AND s.location=?";
        $params[] = $filter_location;
        $types .= "s";
    }
    
    if (!empty($search)) {
        $query .= " AND (s.item_name LIKE ? OR s.part_number LIKE ? OR s.invoice_number LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }
    
    $query .= " ORDER BY s.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!isset($row['subtotal'])) $row['subtotal'] = $row['initial_quantity'] * $row['price'];
        if (!isset($row['total_price'])) $row['total_price'] = $row['subtotal'] - $row['discount'] + $row['tax'];
        $data[] = $row;
    }
    $stmt->close();
    
    // Get filters
    $stmt = $conn->prepare("SELECT DISTINCT location FROM inventory_stock WHERE location IS NOT NULL ORDER BY location");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row['location'];
    }
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT DISTINCT item_type FROM inventory_stock WHERE item_type IS NOT NULL ORDER BY item_type");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $types_list[] = $row['item_type'];
    }
    $stmt->close();
}

// Get item for edit
$item = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM inventory_stock WHERE id=?");
    $stmt->bind_param("i", $_GET['id']);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();

// Helper functions
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
    <title>Inventory Stock — SKDS Logistik</title>
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
    <script>
    function c(){
        const q=+(document.getElementById('q')?.value)||0;
        const p=+(document.getElementById('p')?.value)||0;
        const d=+(document.getElementById('d')?.value)||0;
        const t=+(document.getElementById('t')?.value)||0;
        const sub=q*p;
        const tot=sub-d+t;
        const sdEl=document.getElementById('sd');
        const tdEl=document.getElementById('td');
        if(sdEl) sdEl.value='Rp '+sub.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        if(tdEl) tdEl.value='Rp '+tot.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    }
    function at(){
        const q=+(document.getElementById('q')?.value)||0;
        const p=+(document.getElementById('p')?.value)||0;
        const d=+(document.getElementById('d')?.value)||0;
        const tax=(q*p-d)*0.11;
        if(document.getElementById('t')) document.getElementById('t').value=tax.toFixed(2);
        c();
    }
    window.onload=function(){
        ['q','p','d','t'].forEach(f=>{const e=document.getElementById(f);if(e)e.addEventListener('input',c);});
        if(document.getElementById('q'))c();
    };
    function applyPeriodFilter() {
        const year = document.getElementById('yearFilter').value;
        const month = document.getElementById('monthFilter').value;
        const type = document.getElementById('filterType')?.value || 'all';
        const location = document.getElementById('filterLocation')?.value || 'all';
        const search = document.getElementById('searchBox')?.value || '';
        let url = 'inventory_stock.php?year=' + year + '&month=' + month + '&type=' + type + '&location=' + location;
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
        <a href="inventory_stock.php" class="nav-item active">
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
            <div class="topbar-title">Inventory Stock</div>
            <div class="topbar-sub">Pantau dan kelola persediaan barang di gudang</div>
        </div>
    </header>

    <div class="page-body">

        <?php if($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo $error; ?></span></div>
        <?php endif; ?>

        <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo $success; ?></span></div>
        <?php endif; ?>

        <?php if($action=='create_audit'||($action=='edit'&&$item)): ?>
        <!-- ===== FORM SECTION ===== -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon"><i class="fas fa-<?php echo $action=='create_audit'?'clipboard-check':'pen'; ?>"></i></div>
                    <div class="panel-title"><?php echo $action=='create_audit'?'Input Stock Audit':'Edit Stock'; ?></div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $action=='create_audit'?'create_audit':'update'; ?>">
                <?php if($action=='edit'): ?>
                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                <?php endif; ?>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-file-invoice"></i> Informasi Invoice</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label">Tanggal Invoice *</label>
                            <input type="date" name="invoice_date" required value="<?php echo $item['invoice_date']??date('Y-m-d'); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">No Invoice *</label>
                            <input type="text" name="invoice_number" required value="<?php echo htmlspecialchars($item['invoice_number']??''); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Vendor *</label>
                            <input type="text" name="vendor" required value="<?php echo htmlspecialchars($item['vendor']??''); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Tanggal Kadaluarsa</label>
                            <input type="date" name="expiry_date" value="<?php echo $item['expiry_date']??''; ?>" class="input-field">
                            <p style="color:#6b7280; font-size:11px; margin-top:4px;">Opsional — isi jika barang punya masa kadaluarsa</p>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-clipboard-list"></i> Alokasi & Project</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label">Rencana Alokasi *</label>
                            <input type="text" name="allocation_plan" required value="<?php echo htmlspecialchars($item['allocation_plan']??''); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Project *</label>
                            <input type="text" name="project" required value="<?php echo htmlspecialchars($item['project']??''); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Lokasi Gudang *</label>
                            <input type="text" name="location" required value="<?php echo htmlspecialchars($item['location']??''); ?>" placeholder="Contoh: Gudang A" class="input-field">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-box"></i> Detail Barang</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="field-label">Jenis Barang *</label>
                            <select name="item_type" required class="input-field">
                                <option value="">-- Pilih Jenis Barang --</option>
                                <option value="Sparepart" <?php echo (isset($item['item_type'])&&$item['item_type']=='Sparepart')?'selected':''; ?>>Sparepart</option>
                                <option value="Alat dan Perlengkapan" <?php echo (isset($item['item_type'])&&$item['item_type']=='Alat dan Perlengkapan')?'selected':''; ?>>Alat dan Perlengkapan</option>
                                <option value="Oli, Grease, and Coolant" <?php echo (isset($item['item_type'])&&$item['item_type']=='Oli, Grease, and Coolant')?'selected':''; ?>>Oli, Grease, and Coolant</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Part Number *</label>
                            <input type="text" name="part_number" required value="<?php echo htmlspecialchars($item['part_number']??''); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Nama Barang *</label>
                            <input type="text" name="item_name" required value="<?php echo htmlspecialchars($item['item_name']??''); ?>" class="input-field">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-<?php echo $action=='edit'?'3':'2'; ?> gap-4">
                        <div>
                            <label class="field-label">Qty <?php echo $action=='edit'?'Awal':''; ?> *</label>
                            <input type="number" id="q" name="<?php echo $action=='edit'?'initial_quantity':'quantity'; ?>" required min="0" value="<?php echo $item['initial_quantity']??0; ?>" class="input-field">
                        </div>
                        <?php if($action=='edit'): ?>
                        <div>
                            <label class="field-label">Qty Saat Ini *</label>
                            <input type="number" name="current_quantity" required min="0" value="<?php echo $item['current_quantity']??0; ?>" class="input-field">
                        </div>
                        <?php endif; ?>
                        <div>
                            <label class="field-label">Satuan *</label>
                            <input type="text" name="unit" required value="<?php echo htmlspecialchars($item['unit']??'pcs'); ?>" class="input-field">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-money-bill-wave"></i> Harga (Rp)</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="field-label">Harga Satuan *</label>
                            <input type="number" id="p" name="price" step="0.01" required value="<?php echo $item['price']??0; ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Subtotal</label>
                            <input type="text" id="sd" readonly class="input-field">
                            <p class="field-hint">= Qty &times; Harga Satuan</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="field-label">Diskon (Rp) *</label>
                            <input type="number" id="d" name="discount" step="0.01" required value="<?php echo $item['discount']??0; ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Pajak (Rp) *</label>
                            <input type="number" id="t" name="tax" step="0.01" required value="<?php echo $item['tax']??0; ?>" class="input-field">
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
                    <div>
                        <label class="field-label">Catatan Tambahan</label>
                        <textarea name="notes" rows="3" placeholder="Catatan tambahan..." class="input-field"><?php echo htmlspecialchars($item['notes']??''); ?></textarea>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary" style="flex:1;"><i class="fas fa-save"></i> Simpan Stock</button>
                    <a href="inventory_stock.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary" style="flex:1;"><i class="fas fa-times"></i> Batal</a>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- ===== LIST VIEW ===== -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon"><i class="fas fa-warehouse"></i></div>
                    <div class="panel-title">Inventory Stock</div>
                </div>
                <div class="filter-bar">
                    <select id="yearFilter" onchange="applyPeriodFilter()" class="select-pill">
                        <?php $years = array_unique(array_column($available_periods, 'year')); foreach($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y==$selected_year?'selected':''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="monthFilter" onchange="applyPeriodFilter()" class="select-pill">
                        <?php foreach($month_names as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num==$selected_month?'selected':''; ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="export_stock.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary"><i class="fas fa-download"></i> Export</a>
                    <?php if ($canManage): ?>
                    <a href="inventory_stock.php?action=create_audit&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-primary"><i class="fas fa-plus"></i> Input Audit</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(!empty($data)):
            $total_items = count($data);
            $total_value = array_sum(array_column($data, 'total_price'));
            $total_initial_qty = array_sum(array_column($data, 'initial_quantity'));
            $total_current_qty = array_sum(array_column($data, 'current_quantity'));
            $month_key = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
            $period_display = isset($month_names[$month_key]) ? $month_names[$month_key] : 'Bulan ' . $selected_month;
        ?>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Periode</span><i class="fas fa-calendar stat-card-icon"></i></div>
                <div class="stat-card-value" style="font-size:16px;"><?php echo $period_display . ' ' . $selected_year; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Item</span><i class="fas fa-box stat-card-icon"></i></div>
                <div class="stat-card-value"><?php echo number_format($total_items); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Stock Tersisa</span><i class="fas fa-cubes stat-card-icon"></i></div>
                <div class="stat-card-value"><?php echo number_format($total_current_qty); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Nilai</span><i class="fas fa-money-bill-wave stat-card-icon"></i></div>
                <div class="stat-card-value" style="font-size:16px;"><?php echo formatCurrency($total_value); ?></div>
            </div>
        </div>

        <div class="panel" style="margin-bottom: 24px;">
            <div class="form-section-title" style="margin-bottom: 12px;"><i class="fas fa-filter"></i> Filter & Pencarian</div>
            <div class="filter-bar">
                <input type="text" id="searchBox" placeholder="Cari nama, part number, invoice..." value="<?php echo htmlspecialchars($search??''); ?>" onkeypress="if(event.keyCode==13)applyPeriodFilter();" class="input-field" style="flex:1; min-width:200px;">
                <select id="filterType" onchange="applyPeriodFilter()" class="select-pill">
                    <option value="all" <?php echo ($filter_type??'all')=='all'?'selected':''; ?>>Semua Jenis</option>
                    <?php foreach($types_list as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($filter_type??'')==$t?'selected':''; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterLocation" onchange="applyPeriodFilter()" class="select-pill">
                    <option value="all" <?php echo ($filter_location??'all')=='all'?'selected':''; ?>>Semua Lokasi</option>
                    <?php foreach($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc); ?>" <?php echo ($filter_location??'')==$loc?'selected':''; ?>><?php echo htmlspecialchars($loc); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if(($filter_type??'all')!='all' || ($filter_location??'all')!='all' || !empty($search)): ?>
                <a href="inventory_stock.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary"><i class="fas fa-redo"></i> Reset</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th><th>Tanggal</th><th>Invoice</th><th>Vendor</th><th>Jenis</th><th>Part#</th>
                            <th>Nama Barang</th><th class="text-right">Qty Awal</th><th>Lokasi</th>
                            <th class="text-right">Keluar</th><th class="text-right">Sisa</th><th class="text-center">Status Stock</th><th class="text-center">Kadaluarsa</th><th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no=1;
                        foreach($data as $item):
                            $used = $item['initial_quantity'] - $item['current_quantity'];
                            $percentage = $item['initial_quantity'] > 0 ? ($item['current_quantity'] / $item['initial_quantity']) * 100 : 0;
                            if ($percentage >= 75) { $status_class='badge-aman'; $status_text='AMAN'; }
                            elseif ($percentage >= 30) { $status_class='badge-sedang'; $status_text='SEDANG'; }
                            else { $status_class='badge-kritis'; $status_text='KRITIS'; }
                            $expiry_info = getExpiryStatus($item['expiry_date'] ?? null, $item['current_quantity']);
                        ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($item['invoice_date'])); ?></td>
                            <td style="font-weight:600; color:#6ee7b7;"><?php echo htmlspecialchars($item['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($item['vendor']); ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($item['item_type']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($item['part_number']); ?></td>
                            <td style="font-weight:600; color:#e5e7eb;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="text-right"><?php echo number_format($item['initial_quantity']); ?></td>
                            <td><span class="badge-location"><?php echo htmlspecialchars($item['location']); ?></span></td>
                            <td class="text-right" style="color:#fbbf24;"><?php echo number_format($used); ?></td>
                            <td class="text-right" style="font-weight:700; color:<?php echo $item['current_quantity']>0?'#6ee7b7':'#f87171'; ?>;"><?php echo number_format($item['current_quantity']); ?></td>
                            <td class="text-center"><span class="badge-pill <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td class="text-center">
                                <?php if (empty($item['expiry_date'])): ?>
                                    <span style="color:#6b7280; font-size:11px;">—</span>
                                <?php else: ?>
                                    <div style="font-size:11px; color:#9ca3af;"><?php echo date('d/m/Y', strtotime($item['expiry_date'])); ?></div>
                                    <?php if ($expiry_info): ?>
                                        <span class="badge-pill <?php echo $expiry_info['class']; ?>"><?php echo htmlspecialchars($expiry_info['label']); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canManage): ?>
                                <div class="flex gap-2 justify-center">
                                    <a href="?action=edit&id=<?php echo $item['id']; ?>&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-sm btn-edit">Edit</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus stock ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn-sm btn-delete">Hapus</button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span style="color:#6b7280; font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <div class="panel">
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>Belum Ada Stock</h4>
                <p><?php
                    $month_key = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
                    echo 'Belum ada stock untuk periode ' . (isset($month_names[$month_key]) ? $month_names[$month_key] : 'Bulan ' . $selected_month) . ' ' . $selected_year;
                ?></p>
                <?php if(!empty($search) || ($filter_type??'all')!='all' || ($filter_location??'all')!='all'): ?>
                <a href="inventory_stock.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary"><i class="fas fa-redo"></i> Reset Filter</a>
                <?php else: ?>
                <?php if ($canManage): ?>
                <a href="inventory_stock.php?action=create_audit&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-primary"><i class="fas fa-plus"></i> Input Stock Audit</a>
                <?php endif; ?>
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