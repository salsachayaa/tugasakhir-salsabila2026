<?php
require_once 'includes/functions.php';
requireLogin();

$error = '';
$success = '';
$imported = 0;
$failed = 0;
$errors_detail = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    
    if ($file['error'] == UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, ['xls', 'xlsx', 'csv'])) {
            $error = 'Format file harus Excel (.xls, .xlsx) atau CSV!';
        } else {
            $conn = getDBConnection();
            
            // Untuk semua format, kita akan membaca baris demi baris
            if ($file_ext == 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
            } else {
                // Untuk XLS/XLSX, user perlu save as CSV dulu
                // Atau kita bisa gunakan SimpleXLSX library yang ringan
                
                // Download SimpleXLSX.php dari GitHub jika belum ada
                if (!class_exists('SimpleXLSX')) {
                    // Include SimpleXLSX library (download dari https://github.com/shuchkin/simplexlsx)
                    if (file_exists('includes/SimpleXLSX.php')) {
                        require_once 'includes/SimpleXLSX.php';
                    } else {
                        $error = 'Untuk file Excel, silakan convert ke CSV terlebih dahulu atau hubungi administrator.';
                        $handle = false;
                    }
                }
                
                // Try to parse XLSX using SimpleXLSX
                if (class_exists('SimpleXLSX')) {
                    try {
                        $xlsx = SimpleXLSX::parse($file['tmp_name']);
                        if ($xlsx) {
                            $rows = $xlsx->rows();
                            $is_first_row = true;
                            
                            foreach ($rows as $row_number => $data) {
                                // Skip header
                                if ($is_first_row) {
                                    $is_first_row = false;
                                    continue;
                                }
                                
                                if (count($data) < 8) continue;
                                
                                try {
                                    // Parse data dari Excel
                                    $invoice_date = parseExcelDate($data[0]);
                                    $invoice_number = trim($data[1] ?? '');
                                    $vendor = trim($data[2] ?? '');
                                    $allocation_plan = trim($data[3] ?? '');
                                    $project = trim($data[4] ?? '');
                                    $item_type = validateItemType(trim($data[5] ?? ''));
                                    $part_number = trim($data[6] ?? '');
                                    $item_name = trim($data[7] ?? '');
                                    $quantity = intval($data[8] ?? 0);
                                    $unit = trim($data[9] ?? 'pcs');
                                    $price = floatval($data[10] ?? 0);
                                    $discount = floatval($data[11] ?? 0);
                                    $tax = floatval($data[12] ?? 0);
                                    
                                    if (empty($invoice_number) || empty($vendor) || empty($item_name) || $quantity <= 0) {
                                        $errors_detail[] = "Baris " . ($row_number + 1) . ": Data tidak lengkap";
                                        $failed++;
                                        continue;
                                    }
                                    
                                    if (empty($item_type)) {
                                        $errors_detail[] = "Baris " . ($row_number + 1) . ": Jenis Barang tidak valid";
                                        $failed++;
                                        continue;
                                    }
                                    
                                    $stmt = $conn->prepare("INSERT INTO incoming_goods (user_id, invoice_date, invoice_number, vendor, allocation_plan, project, item_type, part_number, item_name, quantity, unit, price, discount, tax) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("isssssssisdddd", $_SESSION['user_id'], $invoice_date, $invoice_number, $vendor, $allocation_plan, $project, $item_type, $part_number, $item_name, $quantity, $unit, $price, $discount, $tax);
                                    
                                    if ($stmt->execute()) {
                                        $imported++;
                                    } else {
                                        $errors_detail[] = "Baris " . ($row_number + 1) . ": Gagal insert ke database";
                                        $failed++;
                                    }
                                    $stmt->close();
                                } catch (Exception $e) {
                                    $errors_detail[] = "Baris " . ($row_number + 1) . ": " . $e->getMessage();
                                    $failed++;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $error = "Error membaca file Excel: " . $e->getMessage();
                    }
                }
                $handle = false;
            }
            
            // Process CSV
            if ($handle !== false) {
                // Skip header
                fgetcsv($handle);
                
                $row_number = 1;
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    $row_number++;
                    if (count($data) < 8) continue;
                    
                    try {
                        $invoice_date = parseDate($data[0]);
                        $invoice_number = trim($data[1]);
                        $vendor = trim($data[2]);
                        $allocation_plan = trim($data[3] ?? '');
                        $project = trim($data[4] ?? '');
                        $item_type = validateItemType(trim($data[5] ?? ''));
                        $part_number = trim($data[6] ?? '');
                        $item_name = trim($data[7]);
                        $quantity = intval(str_replace([',', ' '], '', $data[8]));
                        $unit = trim($data[9] ?? 'pcs');
                        $price = floatval(str_replace([',', ' ', 'Rp'], '', $data[10] ?? '0'));
                        $discount = floatval(str_replace([',', ' ', 'Rp'], '', $data[11] ?? '0'));
                        $tax = floatval(str_replace([',', ' ', 'Rp'], '', $data[12] ?? '0'));
                        
                        if (empty($invoice_number) || empty($vendor) || empty($item_name) || $quantity <= 0) {
                            $errors_detail[] = "Baris $row_number: Data tidak lengkap atau tidak valid";
                            $failed++;
                            continue;
                        }
                        
                        if (empty($item_type)) {
                            $errors_detail[] = "Baris $row_number: Jenis Barang tidak valid (gunakan: Sparepart, Alat dan Perlengkapan, atau Oli, Grease, and Coolant)";
                            $failed++;
                            continue;
                        }
                        
                        $stmt = $conn->prepare("INSERT INTO incoming_goods (user_id, invoice_date, invoice_number, vendor, allocation_plan, project, item_type, part_number, item_name, quantity, unit, price, discount, tax) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssssssisdddd", $_SESSION['user_id'], $invoice_date, $invoice_number, $vendor, $allocation_plan, $project, $item_type, $part_number, $item_name, $quantity, $unit, $price, $discount, $tax);
                        
                        if ($stmt->execute()) {
                            $imported++;
                        } else {
                            $errors_detail[] = "Baris $row_number: Gagal insert - " . $stmt->error;
                            $failed++;
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $errors_detail[] = "Baris $row_number: " . $e->getMessage();
                        $failed++;
                    }
                }
                fclose($handle);
            }
            
            $conn->close();
            
            if ($imported > 0) {
                $success = "✅ Berhasil import $imported data!" . ($failed > 0 ? " ⚠️ Gagal: $failed data." : "");
            } else if ($failed > 0) {
                $error = "❌ Tidak ada data yang berhasil diimport! Gagal: $failed data.";
            }
        }
    } else {
        $error = 'Terjadi kesalahan saat upload file!';
    }
}

// Helper function to validate and normalize item type
function validateItemType($type) {
    $type = trim($type);
    $valid_types = [
        'Sparepart',
        'Alat dan Perlengkapan',
        'Oli, Grease, and Coolant'
    ];
    
    // Exact match
    if (in_array($type, $valid_types)) {
        return $type;
    }
    
    // Case-insensitive match
    foreach ($valid_types as $valid) {
        if (strcasecmp($type, $valid) === 0) {
            return $valid;
        }
    }
    
    // Partial match for common variations
    $type_lower = strtolower($type);
    if (strpos($type_lower, 'spare') !== false || strpos($type_lower, 'part') !== false) {
        return 'Sparepart';
    }
    if (strpos($type_lower, 'alat') !== false || strpos($type_lower, 'perlengkapan') !== false || strpos($type_lower, 'tools') !== false) {
        return 'Alat dan Perlengkapan';
    }
    if (strpos($type_lower, 'oli') !== false || strpos($type_lower, 'grease') !== false || strpos($type_lower, 'coolant') !== false || strpos($type_lower, 'pelumas') !== false) {
        return 'Oli, Grease, and Coolant';
    }
    
    return ''; // Invalid type
}

// Helper function to parse date
function parseDate($dateStr) {
    $dateStr = trim($dateStr);
    // Try various date formats
    $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateStr);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }
    // Default to today if parsing fails
    return date('Y-m-d');
}

// Helper function to parse Excel date (serial number)
function parseExcelDate($value) {
    if (is_numeric($value)) {
        // Excel date serial number (days since 1900-01-01)
        $unix_timestamp = ($value - 25569) * 86400;
        return date('Y-m-d', $unix_timestamp);
    } else {
        return parseDate($value);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Barang Masuk - User Management System</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
    .type-badge{display:inline-block;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:600;margin:2px;}
    .type-spare{background:#e3f2fd;color:#1565c0;}
    .type-tool{background:#f3e5f5;color:#6a1b9a;}
    .type-oil{background:#fff3e0;color:#e65100;}
    </style>
</head>
<body>
    <div class="navbar">
        <div class="nav-content">
            <h2>Dashboard Admin Gudang</h2>
            <div class="nav-user">
                <span>Halo, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="sidebar">
            <ul class="menu">
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="incoming_goods.php" class="active">Barang Masuk</a></li>
                <li><a href="products.php">Inventory</a></li>
                <li><a href="profile.php">Profil Saya</a></li>
            </ul>
        </div>
        
        <div class="main-content">
            <h1>Import Barang Masuk dari Excel/CSV</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($errors_detail)): ?>
                <div class="alert alert-error">
                    <strong>Detail Error:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <?php foreach (array_slice($errors_detail, 0, 10) as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                        <?php if (count($errors_detail) > 10): ?>
                            <li><em>... dan <?php echo count($errors_detail) - 10; ?> error lainnya</em></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="import-section">
                <h2>📤 Upload File</h2>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="excel_file">Pilih File Excel/CSV *</label>
                        <input type="file" id="excel_file" name="excel_file" accept=".xls,.xlsx,.csv" required>
                        <small>Format: .xls, .xlsx, .csv | Maksimal 10MB</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">📥 Upload & Import</button>
                        <a href="incoming_goods.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
            
            <div class="info-box">
                <h3>📋 Cara Import File Excel Anda</h3>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <strong>⚠️ PENTING - Untuk File Excel (.xlsx/.xls):</strong>
                    <ol style="margin: 10px 0 0 20px;">
                        <li>Buka file Excel Anda</li>
                        <li>Klik <strong>File → Save As</strong></li>
                        <li>Pilih format: <strong>CSV UTF-8 (Comma delimited) (*.csv)</strong></li>
                        <li>Save file</li>
                        <li>Upload file CSV yang baru tersimpan</li>
                    </ol>
                </div>
                
                <h4>Atau Download Template:</h4>
                <p><a href="template_barang_masuk.php" class="btn btn-sm btn-info">📥 Download Template CSV</a></p>
                
                <h4>Format Kolom yang Diperlukan:</h4>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Kolom</th>
                                <th>Contoh</th>
                                <th>Wajib?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Tanggal Invoice</td>
                                <td>19/12/2024</td>
                                <td><span class="badge badge-success">Ya</span></td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>No Invoice</td>
                                <td>INV-001</td>
                                <td><span class="badge badge-success">Ya</span></td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td>Vendor</td>
                                <td>PT Supplier Indonesia</td>
                                <td><span class="badge badge-success">Ya</span></td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td>Rencana Alokasi</td>
                                <td>Gudang A</td>
                                <td>Tidak</td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td>Project</td>
                                <td>Project Alpha</td>
                                <td>Tidak</td>
                            </tr>
                            <tr>
                                <td>6</td>
                                <td><strong>Jenis Barang</strong></td>
                                <td>
                                    <span class="type-badge type-spare">Sparepart</span><br>
                                    <span class="type-badge type-tool">Alat dan Perlengkapan</span><br>
                                    <span class="type-badge type-oil">Oli, Grease, and Coolant</span>
                                </td>
                                <td><span class="badge badge-success">Ya</span></td>
                            </tr>
                            <tr>
                                <td>7</td>
                                <td>Part Number</td>
                                <td>PN-12345</td>
                                <td>Tidak</td>
                            </tr>
                            <tr>
                                <td>8</td>
                                <td>Nama Barang</td>
                                <td>Baut M8</td>
                                <td><span class="badge badge-success">Ya</span></td>
                            </tr>
                            <tr>
                                <td>9</td>
                                <td>Qty</td>
                                <td>100</td>
                                <td><span class="badge badge-success">Ya</span></td>
                            </tr>
                            <tr>
                                <td>10</td>
                                <td>Satuan</td>
                                <td>pcs</td>
                                <td>Tidak</td>
                            </tr>
                            <tr>
                                <td>11</td>
                                <td>Harga</td>
                                <td>5000</td>
                                <td>Tidak</td>
                            </tr>
                            <tr>
                                <td>12</td>
                                <td>Diskon</td>
                                <td>0</td>
                                <td>Tidak</td>
                            </tr>
                            <tr>
                                <td>13</td>
                                <td>Pajak</td>
                                <td>550</td>
                                <td>Tidak</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <h4 style="margin-top: 20px;">💡 Tips:</h4>
                <ul style="margin-left: 20px;">
                    <li>Pastikan kolom header ada di baris pertama</li>
                    <li>Jangan ada baris kosong di tengah data</li>
                    <li>Format tanggal: DD/MM/YYYY (contoh: 19/12/2024)</li>
                    <li><strong>Jenis Barang harus salah satu dari: "Sparepart", "Alat dan Perlengkapan", atau "Oli, Grease, and Coolant"</strong></li>
                    <li>Qty harus berupa angka positif</li>
                    <li>Harga, Diskon, dan Pajak tanpa simbol Rp dan titik pemisah ribuan</li>
                </ul>
                
                <div style="background:#e3f2fd;border:1px solid #90caf9;padding:12px;border-radius:6px;margin-top:15px;">
                    <strong>🔧 Info Jenis Barang:</strong>
                    <p style="margin:8px 0 0;">Sistem akan otomatis mencocokkan variasi penulisan seperti "spare part", "tools", "oli" dengan kategori yang benar.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>