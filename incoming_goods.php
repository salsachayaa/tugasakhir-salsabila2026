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

// Karyawan (view-only) tidak boleh membuka form create/edit
if (!$canManage && in_array($action, ['create', 'edit'])) {
    header('Location: incoming_goods.php?error=noaccess');
    exit;
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Karyawan (view-only) tidak boleh melakukan create/update/delete
    if (!$canManage) {
        header('Location: incoming_goods.php?error=noaccess');
        exit;
    }

    $conn = getDBConnection();

    if ($_POST['action'] === 'create') {
        $stmt = $conn->prepare("INSERT INTO incoming_goods (user_id, invoice_date, expiry_date, invoice_number, vendor, allocation_plan, project, item_type, part_number, item_name, quantity, unit, price, discount, tax, payment_status, payment_due_date, payment_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $payment_due_date = ($_POST['payment_status'] == 'Credit' && !empty($_POST['payment_due_date'])) ? $_POST['payment_due_date'] : null;
        $payment_notes = $_POST['payment_notes'] ?? '';
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        
        $stmt->bind_param("isssssssssssdddsss", 
            $_SESSION['user_id'],
            $_POST['invoice_date'],
            $expiry_date,
            $_POST['invoice_number'],
            $_POST['vendor'],
            $_POST['allocation_plan'],
            $_POST['project'],
            $_POST['item_type'],
            $_POST['part_number'],
            $_POST['item_name'],
            $_POST['quantity'],
            $_POST['unit'],
            $_POST['price'],
            $_POST['discount'],
            $_POST['tax'],
            $_POST['payment_status'],
            $payment_due_date,
            $payment_notes
        );

        if ($stmt->execute()) {
            $incoming_id = $conn->insert_id;
            
            $check_stmt = $conn->prepare("SELECT id, current_quantity, initial_quantity FROM inventory_stock WHERE part_number = ? LIMIT 1");
            $check_stmt->bind_param("s", $_POST['part_number']);
            $check_stmt->execute();
            $existing_stock = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if ($existing_stock) {
                $new_current_qty = $existing_stock['current_quantity'] + (int)$_POST['quantity'];
                $new_initial_qty = $existing_stock['initial_quantity'] + (int)$_POST['quantity'];
                
                // Jika barang masuk baru punya expiry_date, update ke expiry_date yang paling dekat (paling mendesak)
                if (!empty($expiry_date)) {
                    $update_stmt = $conn->prepare("UPDATE inventory_stock SET current_quantity = ?, initial_quantity = ?, expiry_date = IF(expiry_date IS NULL OR ? < expiry_date, ?, expiry_date), updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("iissi", $new_current_qty, $new_initial_qty, $expiry_date, $expiry_date, $existing_stock['id']);
                } else {
                    $update_stmt = $conn->prepare("UPDATE inventory_stock SET current_quantity = ?, initial_quantity = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("iii", $new_current_qty, $new_initial_qty, $existing_stock['id']);
                }
                $update_stmt->execute();
                $update_stmt->close();
                
                $success = '✅ Barang masuk berhasil ditambahkan dan stock diupdate! Stock sekarang: ' . number_format($new_current_qty) . ' ' . $_POST['unit'];
            } else {
                $default_location = 'Gudang Utama';
                
                $insert_stock_stmt = $conn->prepare("INSERT INTO inventory_stock (user_id, invoice_date, expiry_date, invoice_number, vendor, allocation_plan, project, item_type, part_number, item_name, location, initial_quantity, current_quantity, unit, price, discount, tax, stock_type, incoming_goods_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'INCOMING', ?, 'Auto-created from incoming goods')");
                
                $user_id = (int)$_SESSION['user_id'];
                $invoice_date = $_POST['invoice_date'];
                $invoice_number = $_POST['invoice_number'];
                $vendor = $_POST['vendor'];
                $allocation_plan = $_POST['allocation_plan'];
                $project = $_POST['project'];
                $item_type = $_POST['item_type'];
                $part_number = $_POST['part_number'];
                $item_name = $_POST['item_name'];
                $quantity = (int)$_POST['quantity'];
                $unit = $_POST['unit'];
                $price = (float)$_POST['price'];
                $discount = (float)($_POST['discount'] ?? 0);
                $tax = (float)($_POST['tax'] ?? 0);
                
                $insert_stock_stmt->bind_param("issssssssssiisdddi", 
                    $user_id, $invoice_date, $expiry_date, $invoice_number, $vendor, 
                    $allocation_plan, $project, $item_type, $part_number, 
                    $item_name, $default_location, $quantity, $quantity, 
                    $unit, $price, $discount, $tax, $incoming_id
                );
                
                if ($insert_stock_stmt->execute()) {
                    $success = '✅ Barang masuk berhasil ditambahkan dan stock baru dibuat! Stock: ' . number_format($quantity) . ' ' . $unit;
                } else {
                    $error = '⚠️ Barang masuk tersimpan tapi gagal membuat stock: ' . $insert_stock_stmt->error;
                }
                $insert_stock_stmt->close();
            }
            
            $action = 'list';
        } else {
            $error = '❌ Error: ' . $stmt->error;
        }
        $stmt->close();
    }
    elseif ($_POST['action'] === 'update') {
        $get_old = $conn->prepare("SELECT * FROM incoming_goods WHERE id=?");
        $get_old->bind_param("i", $_POST['id']);
        $get_old->execute();
        $old_data = $get_old->get_result()->fetch_assoc();
        $get_old->close();
        
        if ($old_data) {
            $payment_due_date = ($_POST['payment_status'] == 'Credit' && !empty($_POST['payment_due_date'])) ? $_POST['payment_due_date'] : null;
            $payment_notes = $_POST['payment_notes'] ?? '';
            
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $stmt = $conn->prepare("UPDATE incoming_goods SET invoice_date=?, expiry_date=?, invoice_number=?, vendor=?, allocation_plan=?, project=?, item_type=?, part_number=?, item_name=?, quantity=?, unit=?, price=?, discount=?, tax=?, payment_status=?, payment_due_date=?, payment_notes=? WHERE id=?");
            $stmt->bind_param("sssssssssssdddsssi",
                $_POST['invoice_date'], $expiry_date, $_POST['invoice_number'], $_POST['vendor'],
                $_POST['allocation_plan'], $_POST['project'], $_POST['item_type'],
                $_POST['part_number'], $_POST['item_name'], $_POST['quantity'], $_POST['unit'],
                $_POST['price'], $_POST['discount'], $_POST['tax'],
                $_POST['payment_status'], $payment_due_date, $payment_notes,
                $_POST['id']
            );

            if ($stmt->execute()) {
                $part_changed = ($old_data['part_number'] != $_POST['part_number']);
                $qty_changed = ((int)$old_data['quantity'] != (int)$_POST['quantity']);
                
                if ($part_changed || $qty_changed) {
                    $update_old_stock = $conn->prepare("UPDATE inventory_stock SET current_quantity = current_quantity - ?, initial_quantity = initial_quantity - ? WHERE part_number = ?");
                    $old_qty = (int)$old_data['quantity'];
                    $update_old_stock->bind_param("iis", $old_qty, $old_qty, $old_data['part_number']);
                    $update_old_stock->execute();
                    $update_old_stock->close();
                    
                    $check_new_stock = $conn->prepare("SELECT id, current_quantity, initial_quantity FROM inventory_stock WHERE part_number = ?");
                    $check_new_stock->bind_param("s", $_POST['part_number']);
                    $check_new_stock->execute();
                    $new_stock = $check_new_stock->get_result()->fetch_assoc();
                    $check_new_stock->close();
                    
                    if ($new_stock) {
                        $new_qty = (int)$_POST['quantity'];
                        $update_new_stock = $conn->prepare("UPDATE inventory_stock SET current_quantity = current_quantity + ?, initial_quantity = initial_quantity + ? WHERE id = ?");
                        $update_new_stock->bind_param("iii", $new_qty, $new_qty, $new_stock['id']);
                        $update_new_stock->execute();
                        $update_new_stock->close();
                    } else {
                        $default_location = 'Gudang Utama';
                        $insert_new_stock = $conn->prepare("INSERT INTO inventory_stock (user_id, invoice_date, expiry_date, invoice_number, vendor, allocation_plan, project, item_type, part_number, item_name, location, initial_quantity, current_quantity, unit, price, discount, tax, stock_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'INCOMING', 'Auto-created from incoming goods update')");
                        
                        $user_id = (int)$_SESSION['user_id'];
                        $new_qty = (int)$_POST['quantity'];
                        $price = (float)$_POST['price'];
                        $discount = (float)($_POST['discount'] ?? 0);
                        $tax = (float)($_POST['tax'] ?? 0);
                        
                        $insert_new_stock->bind_param("issssssssssiisddd", 
                            $user_id, $_POST['invoice_date'], $expiry_date, $_POST['invoice_number'], $_POST['vendor'],
                            $_POST['allocation_plan'], $_POST['project'], $_POST['item_type'], 
                            $_POST['part_number'], $_POST['item_name'], $default_location,
                            $new_qty, $new_qty, $_POST['unit'], $price, $discount, $tax
                        );
                        $insert_new_stock->execute();
                        $insert_new_stock->close();
                    }
                }
                
                $success = '✅ Data berhasil diupdate dan stock disesuaikan!';
                $action = 'list';
            } else {
                $error = '❌ Gagal update!';
            }
            $stmt->close();
        }
    }
    elseif ($_POST['action'] === 'delete') {
        $get_data = $conn->prepare("SELECT * FROM incoming_goods WHERE id=?");
        $get_data->bind_param("i", $_POST['id']);
        $get_data->execute();
        $data = $get_data->get_result()->fetch_assoc();
        $get_data->close();
        
        if ($data) {
            $delete_stock = $conn->prepare("DELETE FROM inventory_stock WHERE part_number = ?");
            $delete_stock->bind_param("s", $data['part_number']);
            $delete_stock->execute();
            $deleted_stock_count = $delete_stock->affected_rows;
            $delete_stock->close();
            
            $stmt = $conn->prepare("DELETE FROM incoming_goods WHERE id=?");
            $stmt->bind_param("i", $_POST['id']);
            
            if ($stmt->execute()) {
                if ($deleted_stock_count > 0) {
                    $success = '✅ Data barang masuk dan inventory stock (Part#: ' . htmlspecialchars($data['part_number']) . ') berhasil dihapus!';
                } else {
                    $success = '✅ Data barang masuk dihapus (tidak ada stock terkait)!';
                }
            } else {
                $error = '❌ Gagal hapus!';
            }
            $stmt->close();
        }
    }
    $conn->close();
}

$current_year = date('Y');
$available_years = [];
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT DISTINCT YEAR(invoice_date) as year FROM incoming_goods ORDER BY year DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $available_years[] = $row['year'];
}
$stmt->close();

if (empty($available_years)) {
    $available_years[] = $current_year;
}

if (!in_array($selected_year, $available_years)) {
    $selected_year = $available_years[0];
}

$month_names = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$data = [];
$totals = [];
$filter_payment = $_GET['payment'] ?? 'all';

if ($action === 'list') {
    $query = "SELECT * FROM incoming_goods WHERE YEAR(invoice_date)=? AND MONTH(invoice_date)=?";
    $params = [$selected_year, $selected_month];
    $types = "ii";
    
    if ($filter_payment !== 'all') {
        $query .= " AND payment_status=?";
        $params[] = $filter_payment;
        $types .= "s";
    }
    
    $query .= " ORDER BY invoice_date DESC, vendor, invoice_number";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!isset($row['subtotal'])) $row['subtotal'] = $row['quantity'] * $row['price'];
        if (!isset($row['total_price'])) $row['total_price'] = $row['subtotal'] - $row['discount'] + $row['tax'];
        
        $data[] = $row;
        $key = $row['vendor'] . '|' . $row['invoice_number'];
        if (!isset($totals[$key])) {
            $totals[$key] = [
                'vendor' => $row['vendor'], 
                'invoice' => $row['invoice_number'], 
                'date' => $row['invoice_date'], 
                'payment_status' => $row['payment_status'] ?? 'Cash',
                'payment_due_date' => $row['payment_due_date'] ?? null,
                'items' => 0, 
                'qty' => 0, 
                'subtotal' => 0, 
                'discount' => 0, 
                'tax' => 0, 
                'total' => 0
            ];
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

$item = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM incoming_goods WHERE id=?");
    $stmt->bind_param("i", $_GET['id']);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();

$month_key = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
$month_name = isset($month_names[$month_key]) ? $month_names[$month_key] : 'Bulan ' . $selected_month;

function getItemTypeIcon($type) {
    switch($type) {
        case 'Sparepart': return '🔧';
        case 'Alat dan Perlengkapan': return '🛠️';
        case 'Oli, Grease, and Coolant': return '🛢️';
        default: return '📦';
    }
}

function getPaymentStatusBadge($status) {
    if ($status == 'Cash') {
        return '<span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-600">💵 Cash</span>';
    } else {
        return '<span class="px-3 py-1 rounded-full text-xs font-bold bg-orange-600">💳 Credit</span>';
    }
}

$displayName = $_SESSION['user_name'] ?? 'Pengguna';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barang Masuk — SKDS Logistik</title>
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
    function filterData() {
        const year = document.getElementById('yearFilter').value;
        const month = document.getElementById('monthFilter').value;
        const payment = document.getElementById('paymentFilter')?.value || 'all';
        window.location.href = 'incoming_goods.php?year=' + year + '&month=' + month + '&payment=' + payment;
    }
    function c(){
        const q=+(document.getElementById('q').value)||0;
        const p=+(document.getElementById('p').value)||0;
        const d=+(document.getElementById('d').value)||0;
        const t=+(document.getElementById('t').value)||0;
        const sub=q*p;
        const tot=sub-d+t;
        document.getElementById('sd').value='Rp '+sub.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        document.getElementById('td').value='Rp '+tot.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        const info=document.getElementById('ci');
        if(info)info.innerHTML='<strong>Perhitungan:</strong><br>Subtotal = '+q+' &times; Rp '+p.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+' = Rp '+sub.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+'<br>Total = Rp '+sub.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+' - Rp '+d.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+' + Rp '+t.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+' = <strong>Rp '+tot.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g,'.')+'</strong>';
    }
    function at(){
        const q=+(document.getElementById('q').value)||0;
        const p=+(document.getElementById('p').value)||0;
        const d=+(document.getElementById('d').value)||0;
        const tax=(q*p-d)*0.11;
        document.getElementById('t').value=tax.toFixed(2);
        c();
    }
    function toggleDueDate() {
        const status = document.getElementById('payment_status').value;
        const dueDateField = document.getElementById('dueDateField');
        if (status === 'Credit') { dueDateField.style.display = 'block'; }
        else { dueDateField.style.display = 'none'; document.getElementById('payment_due_date').value = ''; }
    }
    function filterByType(type){
        const groups=document.querySelectorAll('.invoice-group');
        const buttons=document.querySelectorAll('.filter-chip');
        buttons.forEach(btn=>{
            btn.classList.remove('active');
            if(btn.getAttribute('data-type')===type){ btn.classList.add('active'); }
        });
        groups.forEach(group=>{
            if(type==='all'){
                group.style.display='block';
                group.querySelectorAll('tbody tr:not(.total-row)').forEach(row=>{row.style.display='';});
            }else{
                const rows=group.querySelectorAll('tbody tr:not(.total-row)');
                let hasMatch=false;
                rows.forEach(row=>{
                    const itemType=row.getAttribute('data-item-type');
                    if(itemType===type){ hasMatch=true; row.style.display=''; }
                    else{ row.style.display='none'; }
                });
                group.style.display=hasMatch?'block':'none';
            }
        });
    }
    window.onload=function(){
        ['q','p','d','t'].forEach(f=>{const e=document.getElementById(f);if(e)e.addEventListener('input',c);});
        if(document.getElementById('q'))c();
        if(document.getElementById('payment_status'))toggleDueDate();
    };
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
        <a href="incoming_goods.php" class="nav-item active">
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
            <div class="topbar-title">Barang Masuk</div>
            <div class="topbar-sub">Kelola data penerimaan barang dan invoice</div>
        </div>
    </header>

    <div class="page-body">

        <?php if($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo $error; ?></span></div>
        <?php endif; ?>

        <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo $success; ?></span></div>
        <?php endif; ?>

        <?php if($action=='create'||($action=='edit'&&$item)): ?>
        <!-- ===== FORM SECTION ===== -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon"><i class="fas fa-<?php echo $action=='create'?'plus':'pen'; ?>"></i></div>
                    <div class="panel-title"><?php echo $action=='create'?'Tambah':'Edit'; ?> Barang Masuk</div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $action=='create'?'create':'update'; ?>">
                <?php if($action=='edit'): ?>
                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                <?php endif; ?>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-file-invoice"></i> Invoice</div>
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
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-money-check-alt"></i> Status Pembayaran</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label">Status Pembayaran *</label>
                            <select id="payment_status" name="payment_status" required onchange="toggleDueDate()" class="input-field">
                                <option value="Cash" <?php echo (isset($item['payment_status'])&&$item['payment_status']=='Cash')?'selected':''; ?>>Cash</option>
                                <option value="Credit" <?php echo (isset($item['payment_status'])&&$item['payment_status']=='Credit')?'selected':''; ?>>Credit</option>
                            </select>
                        </div>
                        <div id="dueDateField" style="display:<?php echo (isset($item['payment_status'])&&$item['payment_status']=='Credit')?'block':'none'; ?>;">
                            <label class="field-label">Tanggal Jatuh Tempo</label>
                            <input type="date" id="payment_due_date" name="payment_due_date" value="<?php echo $item['payment_due_date']??''; ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Catatan Pembayaran</label>
                            <input type="text" name="payment_notes" value="<?php echo htmlspecialchars($item['payment_notes']??''); ?>" placeholder="Opsional" class="input-field">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-clipboard-list"></i> Alokasi</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="field-label">Rencana Alokasi *</label>
                            <input type="text" name="allocation_plan" required value="<?php echo htmlspecialchars($item['allocation_plan']??''); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Project *</label>
                            <input type="text" name="project" required value="<?php echo htmlspecialchars($item['project']??''); ?>" class="input-field">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-box"></i> Barang</div>
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="field-label">Quantity *</label>
                            <input type="number" id="q" name="quantity" required min="1" value="<?php echo $item['quantity']??1; ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Satuan *</label>
                            <input type="text" name="unit" required value="<?php echo htmlspecialchars($item['unit']??'pcs'); ?>" class="input-field">
                        </div>
                        <div>
                            <label class="field-label">Tanggal Kadaluarsa</label>
                            <input type="date" name="expiry_date" value="<?php echo $item['expiry_date']??''; ?>" class="input-field">
                            <p style="color:#6b7280; font-size:11px; margin-top:4px;">Opsional — isi jika barang punya masa kadaluarsa</p>
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
                    <div id="ci" class="calc-box"><strong>Perhitungan:</strong> Isi form untuk melihat kalkulasi</div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary" style="flex:1;"><i class="fas fa-save"></i> Simpan Data</button>
                    <a href="incoming_goods.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary" style="flex:1;"><i class="fas fa-times"></i> Batal</a>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- ===== LIST VIEW ===== -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon"><i class="fas fa-box"></i></div>
                    <div class="panel-title">Barang Masuk</div>
                </div>
                <div class="filter-bar">
                    <select id="yearFilter" onchange="filterData()" class="select-pill">
                        <?php foreach($available_years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y==$selected_year?'selected':''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="monthFilter" onchange="filterData()" class="select-pill">
                        <?php foreach($month_names as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num == $month_key ? 'selected' : ''; ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="paymentFilter" onchange="filterData()" class="select-pill">
                        <option value="all" <?php echo $filter_payment=='all'?'selected':''; ?>>Semua Payment</option>
                        <option value="Cash" <?php echo $filter_payment=='Cash'?'selected':''; ?>>Cash</option>
                        <option value="Credit" <?php echo $filter_payment=='Credit'?'selected':''; ?>>Credit</option>
                    </select>
                    <a href="export_incoming.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-secondary"><i class="fas fa-download"></i> Export</a>
                    <?php if ($canManage): ?>
                    <a href="incoming_goods.php?action=create&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-primary"><i class="fas fa-plus"></i> Tambah</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(!empty($data)): ?>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Periode</span><i class="fas fa-calendar stat-card-icon"></i></div>
                <div class="stat-card-value" style="font-size:16px;"><?php echo $month_name . ' ' . $selected_year; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Item</span><i class="fas fa-box stat-card-icon"></i></div>
                <div class="stat-card-value"><?php echo number_format(count($data)); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Vendor</span><i class="fas fa-building stat-card-icon"></i></div>
                <div class="stat-card-value"><?php echo number_format(count(array_unique(array_column($data, 'vendor')))); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-head"><span class="stat-card-label">Total Nilai</span><i class="fas fa-money-bill-wave stat-card-icon"></i></div>
                <div class="stat-card-value" style="font-size:16px;"><?php echo formatCurrency(array_sum(array_column($data, 'total_price'))); ?></div>
            </div>
        </div>

        <div class="panel" style="margin-bottom: 24px;">
            <div class="form-section-title" style="margin-bottom: 12px;"><i class="fas fa-filter"></i> Filter Jenis Barang</div>
            <div class="filter-bar">
                <button class="filter-chip active" data-type="all" onclick="filterByType('all')">Semua</button>
                <button class="filter-chip" data-type="Sparepart" onclick="filterByType('Sparepart')">Sparepart</button>
                <button class="filter-chip" data-type="Alat dan Perlengkapan" onclick="filterByType('Alat dan Perlengkapan')">Alat dan Perlengkapan</button>
                <button class="filter-chip" data-type="Oli, Grease, and Coolant" onclick="filterByType('Oli, Grease, and Coolant')">Oli, Grease, and Coolant</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if(empty($data)): ?>
        <div class="panel">
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>Belum Ada Data</h4>
                <p>Belum ada data untuk periode <?php echo $month_name . ' ' . $selected_year; ?></p>
                <?php if ($canManage): ?>
                <a href="incoming_goods.php?action=create&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-primary"><i class="fas fa-plus"></i> Tambah Barang Masuk</a>
                <?php endif; ?>
            </div>
        </div>
        <?php else:
            $grouped=[];
            foreach($data as $i){$k=$i['vendor'].'|'.$i['invoice_number'];$grouped[$k][]=$i;}
            foreach($grouped as $k=>$items):$t=$totals[$k];
        ?>
        <div class="invoice-group group-card">
            <div class="group-header">
                <div class="group-header-left">
                    <strong><?php echo htmlspecialchars($t['vendor']); ?></strong>
                    <span class="sep">|</span>
                    <span><?php echo htmlspecialchars($t['invoice']); ?></span>
                    <span class="sep">|</span>
                    <span><?php echo date('d/m/Y',strtotime($t['date'])); ?></span>
                    <span class="sep">|</span>
                    <?php if($t['payment_status']=='Cash'): ?>
                    <span class="badge-pill badge-cash">Cash</span>
                    <?php else: ?>
                    <span class="badge-pill badge-credit">Credit</span>
                    <?php endif; ?>
                    <?php if($t['payment_status']=='Credit' && $t['payment_due_date']): ?>
                    <span class="sep">|</span>
                    <span style="color:#fbbf24;">Jth Tempo: <?php echo date('d/m/Y',strtotime($t['payment_due_date'])); ?></span>
                    <?php endif; ?>
                </div>
                <div class="group-header-right"><?php echo $t['items']; ?> Item &middot; <?php echo formatCurrency($t['total']); ?></div>
            </div>

            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th><th>Jenis</th><th>Alokasi</th><th>Project</th><th>Part#</th><th>Nama Barang</th>
                            <th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th>
                            <th class="text-right">Diskon</th><th class="text-right">Pajak</th><th class="text-right">Total</th><th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no=1;foreach($items as $i): ?>
                        <tr data-item-type="<?php echo htmlspecialchars($i['item_type']??''); ?>">
                            <td><?php echo $no++; ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($i['item_type']??'-'); ?></td>
                            <td><?php echo htmlspecialchars($i['allocation_plan']); ?></td>
                            <td><?php echo htmlspecialchars($i['project']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($i['part_number']); ?></td>
                            <td style="font-weight:600; color:#e5e7eb;"><?php echo htmlspecialchars($i['item_name']); ?></td>
                            <td class="text-right mono"><?php echo number_format($i['quantity']); ?> <?php echo $i['unit']; ?></td>
                            <td class="text-right"><?php echo formatCurrency($i['price']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($i['subtotal']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($i['discount']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($i['tax']); ?></td>
                            <td class="text-right" style="font-weight:700; color:#6ee7b7;"><?php echo formatCurrency($i['total_price']); ?></td>
                            <td>
                                <?php if ($canManage): ?>
                                <div class="flex gap-2 justify-center">
                                    <a href="?action=edit&id=<?php echo $i['id']; ?>&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn-sm btn-edit">Edit</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus data ini?\n\nStock terkait akan dihapus!');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $i['id']; ?>">
                                        <button type="submit" class="btn-sm btn-delete">Hapus</button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span style="color:#6b7280; font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="8" class="text-right">TOTAL INVOICE:</td>
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
        <?php endforeach;endif; ?>
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