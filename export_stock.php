<?php
require_once 'includes/functions.php';
requireLogin();

// Get year and month parameter
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');

// Month names
$month_names = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Get all stock data for selected year and month
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT s.*, 
                       (SELECT COUNT(*) FROM stock_outgoing_history WHERE inventory_stock_id = s.id) as outgoing_count,
                       (s.initial_quantity - s.current_quantity) as used_quantity
                       FROM inventory_stock s 
                       WHERE s.user_id=? AND YEAR(s.invoice_date)=? AND MONTH(s.invoice_date)=?
                       ORDER BY s.invoice_date DESC, s.invoice_number");
$stmt->bind_param("iii", $_SESSION['user_id'], $selected_year, $selected_month);
$stmt->execute();
$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    if (!isset($row['subtotal'])) $row['subtotal'] = $row['initial_quantity'] * $row['price'];
    if (!isset($row['discount'])) $row['discount'] = 0;
    if (!isset($row['tax'])) $row['tax'] = 0;
    if (!isset($row['total_price'])) $row['total_price'] = $row['subtotal'] - $row['discount'] + $row['tax'];
    $data[] = $row;
}
$stmt->close();

// Get outgoing history for each stock
$stock_history = [];
foreach ($data as $stock) {
    $stmt = $conn->prepare("SELECT h.outgoing_date, h.spb_number, h.quantity, h.remaining_stock 
                           FROM stock_outgoing_history h
                           WHERE h.inventory_stock_id=?
                           ORDER BY h.outgoing_date");
    $stmt->bind_param("i", $stock['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
    $stock_history[$stock['id']] = $history;
}

$conn->close();

// Set headers for Excel download
$period_text = $month_names[$selected_month] . "_" . $selected_year;
$filename = "Inventory_Stock_" . $period_text . "_" . date('Ymd_His') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; font-size: 11px; vertical-align: top; }
        th { background-color: #4CAF50; color: white; font-weight: bold; }
        .number { mso-number-format: "\#\,\#\#0"; }
        .currency { mso-number-format: "\#\,\#\#0\.00"; }
        .date { mso-number-format: "dd\/mm\/yyyy"; }
        .header-row { background-color: #E8F5E9; font-weight: bold; }
        .grand-total { background-color: #4CAF50; color: white; font-weight: bold; font-size: 12pt; }
        .type-spare { background-color: #E3F2FD; }
        .type-tool { background-color: #F3E5F5; }
        .type-oil { background-color: #FFF3E0; }
        .history-row { background-color: #FFF9C4; font-size: 10px; }
    </style>
</head>
<body>
    <h2>LAPORAN INVENTORY STOCK</h2>
    <h3>PERIODE: <?php echo strtoupper($month_names[$selected_month]) . ' ' . $selected_year; ?></h3>
    <p>Tanggal Cetak: <?php echo date('d/m/Y H:i:s'); ?></p>
    <p>User: <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
    <br>
    
    <table>
        <thead>
            <tr>
                <th rowspan="2">No</th>
                <th rowspan="2">Tgl Invoice</th>
                <th rowspan="2">No Invoice/Faktur</th>
                <th rowspan="2">Vendor</th>
                <th rowspan="2">Rencana Alokasi</th>
                <th rowspan="2">Project</th>
                <th rowspan="2">Jenis Barang</th>
                <th rowspan="2">Part Number</th>
                <th rowspan="2">Nama Barang</th>
                <th rowspan="2">Qty Awal</th>
                <th rowspan="2">Sat</th>
                <th rowspan="2">Harga</th>
                <th rowspan="2">Subtotal</th>
                <th rowspan="2">Diskon</th>
                <th rowspan="2">Pajak</th>
                <th rowspan="2">Total</th>
                <th rowspan="2">Lokasi</th>
                <th colspan="4" style="text-align:center; background-color: #FFE0B2;">Riwayat Barang Keluar</th>
            </tr>
            <tr>
                <th style="background-color: #FFE0B2;">Qty Keluar</th>
                <th style="background-color: #FFE0B2;">Tgl Keluar</th>
                <th style="background-color: #FFE0B2;">SPB</th>
                <th style="background-color: #FFE0B2;">Sisa</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $grand_initial_qty = 0;
            $grand_used_qty = 0;
            $grand_current_qty = 0;
            $grand_value = 0;
            
            foreach($data as $stock):
                $grand_initial_qty += $stock['initial_quantity'];
                $grand_used_qty += $stock['used_quantity'];
                $grand_current_qty += $stock['current_quantity'];
                $grand_value += $stock['total_price'];
                
                $type_class = '';
                if (isset($stock['item_type'])) {
                    switch($stock['item_type']) {
                        case 'Sparepart': $type_class = 'type-spare'; break;
                        case 'Alat dan Perlengkapan': $type_class = 'type-tool'; break;
                        case 'Oli, Grease, and Coolant': $type_class = 'type-oil'; break;
                    }
                }
                
                $history = $stock_history[$stock['id']] ?? [];
                
                // First row with stock info
            ?>
            <tr class="<?php echo $type_class; ?>">
                <td><?php echo $no++; ?></td>
                <td class="date"><?php echo date('d/m/Y', strtotime($stock['invoice_date'])); ?></td>
                <td><?php echo htmlspecialchars($stock['invoice_number']); ?></td>
                <td><?php echo htmlspecialchars($stock['vendor']); ?></td>
                <td><?php echo htmlspecialchars($stock['allocation_plan']); ?></td>
                <td><?php echo htmlspecialchars($stock['project']); ?></td>
                <td><strong><?php echo htmlspecialchars($stock['item_type'] ?? '-'); ?></strong></td>
                <td><?php echo htmlspecialchars($stock['part_number']); ?></td>
                <td><?php echo htmlspecialchars($stock['item_name']); ?></td>
                <td class="number"><?php echo $stock['initial_quantity']; ?></td>
                <td><?php echo htmlspecialchars($stock['unit']); ?></td>
                <td class="currency"><?php echo $stock['price']; ?></td>
                <td class="currency"><?php echo $stock['subtotal']; ?></td>
                <td class="currency"><?php echo $stock['discount']; ?></td>
                <td class="currency"><?php echo $stock['tax']; ?></td>
                <td class="currency"><?php echo $stock['total_price']; ?></td>
                <td><?php echo htmlspecialchars($stock['location']); ?></td>
                <?php if (!empty($history)): 
                    $first = $history[0];
                ?>
                <td class="number"><?php echo $first['quantity']; ?></td>
                <td class="date"><?php echo date('d/m/Y', strtotime($first['outgoing_date'])); ?></td>
                <td><?php echo htmlspecialchars($first['spb_number']); ?></td>
                <td class="number"><?php echo $first['remaining_stock']; ?></td>
                <?php else: ?>
                <td>-</td>
                <td>-</td>
                <td>-</td>
                <td class="number"><?php echo $stock['current_quantity']; ?></td>
                <?php endif; ?>
            </tr>
            <?php 
                // Additional history rows (if any)
                if (count($history) > 1) {
                    for ($i = 1; $i < count($history); $i++) {
                        $h = $history[$i];
            ?>
            <tr class="history-row">
                <td colspan="17"></td>
                <td class="number"><?php echo $h['quantity']; ?></td>
                <td class="date"><?php echo date('d/m/Y', strtotime($h['outgoing_date'])); ?></td>
                <td><?php echo htmlspecialchars($h['spb_number']); ?></td>
                <td class="number"><?php echo $h['remaining_stock']; ?></td>
            </tr>
            <?php 
                    }
                }
            endforeach; 
            ?>
            
            <!-- Grand Total Row -->
            <tr class="grand-total">
                <td colspan="9" style="text-align:right;"><strong>GRAND TOTAL:</strong></td>
                <td class="number"><strong><?php echo $grand_initial_qty; ?></strong></td>
                <td colspan="5" style="text-align:right;"><strong>TOTAL NILAI INVENTORY:</strong></td>
                <td class="currency"><strong><?php echo $grand_value; ?></strong></td>
                <td></td>
                <td class="number" style="text-align:right;"><strong>Total Keluar:</strong></td>
                <td class="number"><strong><?php echo $grand_used_qty; ?></strong></td>
                <td style="text-align:right;"><strong>Stock Tersisa:</strong></td>
                <td class="number"><strong><?php echo $grand_current_qty; ?></strong></td>
            </tr>
        </tbody>
    </table>
    
    <br><br>
    <h3>RINGKASAN BERDASARKAN JENIS BARANG</h3>
    <table>
        <thead>
            <tr>
                <th>Jenis Barang</th>
                <th>Jumlah Item</th>
                <th>Qty Awal</th>
                <th>Qty Keluar</th>
                <th>Qty Tersisa</th>
                <th>Total Nilai (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $summary = [];
            foreach ($data as $row) {
                $type = $row['item_type'] ?? 'Tidak Dikategorikan';
                if (!isset($summary[$type])) {
                    $summary[$type] = [
                        'count' => 0, 
                        'initial_qty' => 0, 
                        'used_qty' => 0,
                        'current_qty' => 0,
                        'total' => 0
                    ];
                }
                $summary[$type]['count']++;
                $summary[$type]['initial_qty'] += $row['initial_quantity'];
                $summary[$type]['used_qty'] += $row['used_quantity'];
                $summary[$type]['current_qty'] += $row['current_quantity'];
                $summary[$type]['total'] += $row['total_price'];
            }
            
            foreach ($summary as $type => $stats):
                $type_class = '';
                switch($type) {
                    case 'Sparepart': $type_class = 'type-spare'; break;
                    case 'Alat dan Perlengkapan': $type_class = 'type-tool'; break;
                    case 'Oli, Grease, and Coolant': $type_class = 'type-oil'; break;
                }
            ?>
            <tr class="<?php echo $type_class; ?>">
                <td><strong><?php echo htmlspecialchars($type); ?></strong></td>
                <td class="number"><?php echo $stats['count']; ?></td>
                <td class="number"><?php echo $stats['initial_qty']; ?></td>
                <td class="number"><?php echo $stats['used_qty']; ?></td>
                <td class="number"><?php echo $stats['current_qty']; ?></td>
                <td class="currency"><?php echo $stats['total']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <br><br>
    <h3>RINGKASAN BERDASARKAN LOKASI</h3>
    <table>
        <thead>
            <tr>
                <th>Lokasi</th>
                <th>Jumlah Item</th>
                <th>Qty Awal</th>
                <th>Qty Tersisa</th>
                <th>Total Nilai (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $location_summary = [];
            foreach ($data as $row) {
                $loc = $row['location'] ?? 'Tidak Ada Lokasi';
                if (!isset($location_summary[$loc])) {
                    $location_summary[$loc] = [
                        'count' => 0, 
                        'initial_qty' => 0, 
                        'current_qty' => 0,
                        'total' => 0
                    ];
                }
                $location_summary[$loc]['count']++;
                $location_summary[$loc]['initial_qty'] += $row['initial_quantity'];
                $location_summary[$loc]['current_qty'] += $row['current_quantity'];
                $location_summary[$loc]['total'] += $row['total_price'];
            }
            
            foreach ($location_summary as $loc => $stats):
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($loc); ?></strong></td>
                <td class="number"><?php echo $stats['count']; ?></td>
                <td class="number"><?php echo $stats['initial_qty']; ?></td>
                <td class="number"><?php echo $stats['current_qty']; ?></td>
                <td class="currency"><?php echo $stats['total']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <br><br>
    <h3>RINGKASAN STATUS STOCK</h3>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th>Jumlah Item</th>
                <th>Persentase</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $status_summary = [
                'Aman (≥75%)' => 0,
                'Sedang (30-74%)' => 0,
                'Kritis (<30%)' => 0,
                'Kosong (0%)' => 0
            ];
            
            foreach ($data as $row) {
                if ($row['initial_quantity'] == 0) {
                    $status_summary['Kosong (0%)']++;
                } else {
                    $percentage = ($row['current_quantity'] / $row['initial_quantity']) * 100;
                    if ($percentage >= 75) {
                        $status_summary['Aman (≥75%)']++;
                    } elseif ($percentage >= 30) {
                        $status_summary['Sedang (30-74%)']++;
                    } else {
                        $status_summary['Kritis (<30%)']++;
                    }
                }
            }
            
            $total_items = count($data);
            foreach ($status_summary as $status => $count):
                $pct = $total_items > 0 ? ($count / $total_items * 100) : 0;
            ?>
            <tr>
                <td><strong><?php echo $status; ?></strong></td>
                <td class="number"><?php echo $count; ?></td>
                <td><?php echo number_format($pct, 1); ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>