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

// Get all outgoing goods data for selected year and month
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT o.*, s.location, s.item_type, s.invoice_number as stock_invoice 
                       FROM outgoing_goods o
                       LEFT JOIN inventory_stock s ON o.inventory_stock_id = s.id
                       WHERE o.user_id = ? AND YEAR(o.outgoing_date) = ? AND MONTH(o.outgoing_date) = ?
                       ORDER BY o.outgoing_date DESC, o.spb_number");
$stmt->bind_param("iii", $_SESSION['user_id'], $selected_year, $selected_month);
$stmt->execute();
$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    if (!isset($row['subtotal'])) $row['subtotal'] = $row['quantity'] * $row['price'];
    if (!isset($row['discount'])) $row['discount'] = 0;
    if (!isset($row['tax'])) $row['tax'] = 0;
    if (!isset($row['total_price'])) $row['total_price'] = $row['subtotal'] - $row['discount'] + $row['tax'];
    $data[] = $row;
}

$stmt->close();
$conn->close();

// Group by SPB for totals
$grouped_spb = [];
foreach ($data as $row) {
    $spb = $row['spb_number'];
    if (!isset($grouped_spb[$spb])) {
        $grouped_spb[$spb] = [
            'spb' => $spb,
            'date' => $row['outgoing_date'],
            'allocation' => $row['allocation_plan'],
            'items' => []
        ];
    }
    $grouped_spb[$spb]['items'][] = $row;
}

// Set headers for Excel download
$period_text = $month_names[$selected_month] . "_" . $selected_year;
$filename = "Barang_Keluar_" . $period_text . "_" . date('Ymd_His') . ".xls";
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
        th, td { border: 1px solid #000; padding: 5px; text-align: left; font-size: 11px; }
        th { background-color: #e65100; color: white; font-weight: bold; }
        .number { mso-number-format: "\#\,\#\#0"; }
        .currency { mso-number-format: "\#\,\#\#0\.00"; }
        .date { mso-number-format: "dd\/mm\/yyyy"; }
        .spb-total { background-color: #FFE0B2; font-weight: bold; }
        .grand-total { background-color: #FF9800; color: white; font-weight: bold; font-size: 12pt; }
        .type-spare { background-color: #E3F2FD; }
        .type-tool { background-color: #F3E5F5; }
        .type-oil { background-color: #FFF3E0; }
    </style>
</head>
<body>
    <h2>LAPORAN BARANG KELUAR</h2>
    <h3>PERIODE: <?php echo strtoupper($month_names[$selected_month]) . ' ' . $selected_year; ?></h3>
    <p>Tanggal Cetak: <?php echo date('d/m/Y H:i:s'); ?></p>
    <p>User: <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
    <br>
    
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tgl Keluar</th>
                <th>No. SPB</th>
                <th>Rencana Alokasi</th>
                <th>Jenis Barang</th>
                <th>Part Number</th>
                <th>Nama Barang</th>
                <th>Qty</th>
                <th>Satuan</th>
                <th>Harga Satuan (Rp)</th>
                <th>Subtotal (Rp)</th>
                <th>Diskon (Rp)</th>
                <th>Pajak (Rp)</th>
                <th>Total (Rp)</th>
                <th>No Invoice / Faktur</th>
                <th>Lokasi</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $grand_subtotal = 0;
            $grand_discount = 0;
            $grand_tax = 0;
            $grand_total = 0;
            
            foreach ($grouped_spb as $spb_data):
                // Calculate SPB totals
                $spb_subtotal = 0;
                $spb_discount = 0;
                $spb_tax = 0;
                $spb_total = 0;
                
                // Print all items for this SPB
                foreach ($spb_data['items'] as $row):
                    $spb_subtotal += $row['subtotal'];
                    $spb_discount += $row['discount'];
                    $spb_tax += $row['tax'];
                    $spb_total += $row['total_price'];
                    
                    $type_class = '';
                    if (isset($row['item_type'])) {
                        switch($row['item_type']) {
                            case 'Sparepart': $type_class = 'type-spare'; break;
                            case 'Alat dan Perlengkapan': $type_class = 'type-tool'; break;
                            case 'Oli, Grease, and Coolant': $type_class = 'type-oil'; break;
                        }
                    }
            ?>
            <tr class="<?php echo $type_class; ?>">
                <td><?php echo $no++; ?></td>
                <td class="date"><?php echo date('d/m/Y', strtotime($row['outgoing_date'])); ?></td>
                <td><?php echo htmlspecialchars($row['spb_number']); ?></td>
                <td><?php echo htmlspecialchars($row['allocation_plan']); ?></td>
                <td><strong><?php echo htmlspecialchars($row['item_type'] ?? '-'); ?></strong></td>
                <td><?php echo htmlspecialchars($row['part_number']); ?></td>
                <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                <td class="number"><?php echo $row['quantity']; ?></td>
                <td><?php echo htmlspecialchars($row['unit']); ?></td>
                <td class="currency"><?php echo $row['price']; ?></td>
                <td class="currency"><?php echo $row['subtotal']; ?></td>
                <td class="currency"><?php echo $row['discount']; ?></td>
                <td class="currency"><?php echo $row['tax']; ?></td>
                <td class="currency"><?php echo $row['total_price']; ?></td>
                <td><?php echo htmlspecialchars($row['invoice_number'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($row['location'] ?? '-'); ?></td>
            </tr>
            <?php 
                endforeach;
                
                // Add to grand totals
                $grand_subtotal += $spb_subtotal;
                $grand_discount += $spb_discount;
                $grand_tax += $spb_tax;
                $grand_total += $spb_total;
            ?>
            <!-- SPB Total Row -->
            <tr class="spb-total">
                <td colspan="10" style="text-align:right;"><strong>TOTAL SPB: <?php echo htmlspecialchars($spb_data['spb']); ?></strong></td>
                <td class="currency"><strong><?php echo $spb_subtotal; ?></strong></td>
                <td class="currency"><strong><?php echo $spb_discount; ?></strong></td>
                <td class="currency"><strong><?php echo $spb_tax; ?></strong></td>
                <td class="currency"><strong><?php echo $spb_total; ?></strong></td>
                <td colspan="2"></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Grand Total Row -->
            <tr class="grand-total">
                <td colspan="10" style="text-align:right;"><strong>GRAND TOTAL:</strong></td>
                <td class="currency"><strong><?php echo $grand_subtotal; ?></strong></td>
                <td class="currency"><strong><?php echo $grand_discount; ?></strong></td>
                <td class="currency"><strong><?php echo $grand_tax; ?></strong></td>
                <td class="currency"><strong><?php echo $grand_total; ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
    
    <br><br>
    <h3>RINGKASAN BERDASARKAN JENIS BARANG</h3>
    <table>
        <thead>
            <tr>
                <th>Jenis Barang</th>
                <th>Jumlah Transaksi</th>
                <th>Total Qty</th>
                <th>Total Nilai (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $summary = [];
            foreach ($data as $row) {
                $type = $row['item_type'] ?? 'Tidak Dikategorikan';
                if (!isset($summary[$type])) {
                    $summary[$type] = ['count' => 0, 'qty' => 0, 'total' => 0];
                }
                $summary[$type]['count']++;
                $summary[$type]['qty'] += $row['quantity'];
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
                <td class="number"><?php echo $stats['qty']; ?></td>
                <td class="currency"><?php echo $stats['total']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <br><br>
    <h3>RINGKASAN BERDASARKAN SPB</h3>
    <table>
        <thead>
            <tr>
                <th>No. SPB</th>
                <th>Tanggal</th>
                <th>Alokasi</th>
                <th>Jumlah Item</th>
                <th>Total Nilai (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grouped_spb as $spb_data): 
                $spb_total_value = 0;
                foreach ($spb_data['items'] as $item) {
                    $spb_total_value += $item['total_price'];
                }
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($spb_data['spb']); ?></strong></td>
                <td class="date"><?php echo date('d/m/Y', strtotime($spb_data['date'])); ?></td>
                <td><?php echo htmlspecialchars($spb_data['allocation']); ?></td>
                <td class="number"><?php echo count($spb_data['items']); ?></td>
                <td class="currency"><?php echo $spb_total_value; ?></td>
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
                <th>Jumlah Transaksi</th>
                <th>Total Qty</th>
                <th>Total Nilai (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $location_summary = [];
            foreach ($data as $row) {
                $loc = $row['location'] ?? 'Tidak Ada Lokasi';
                if (!isset($location_summary[$loc])) {
                    $location_summary[$loc] = ['count' => 0, 'qty' => 0, 'total' => 0];
                }
                $location_summary[$loc]['count']++;
                $location_summary[$loc]['qty'] += $row['quantity'];
                $location_summary[$loc]['total'] += $row['total_price'];
            }
            
            foreach ($location_summary as $loc => $stats):
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($loc); ?></strong></td>
                <td class="number"><?php echo $stats['count']; ?></td>
                <td class="number"><?php echo $stats['qty']; ?></td>
                <td class="currency"><?php echo $stats['total']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>