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

// Get all incoming goods data for selected year and month
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM incoming_goods WHERE user_id = ? AND YEAR(invoice_date) = ? AND MONTH(invoice_date) = ? ORDER BY invoice_date DESC, vendor, invoice_number");
$stmt->bind_param("iii", $_SESSION['user_id'], $selected_year, $selected_month);
$stmt->execute();
$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    // Ensure all calculated fields exist
    if (!isset($row['subtotal'])) $row['subtotal'] = $row['quantity'] * $row['price'];
    if (!isset($row['discount'])) $row['discount'] = 0;
    if (!isset($row['tax'])) $row['tax'] = 0;
    if (!isset($row['total_price'])) $row['total_price'] = $row['subtotal'] - $row['discount'] + $row['tax'];
    if (!isset($row['payment_status'])) $row['payment_status'] = 'Cash';
    
    $data[] = $row;
}

$stmt->close();
$conn->close();

// Group by invoice for totals
$grouped = [];
foreach ($data as $row) {
    $key = $row['vendor'] . '|||' . $row['invoice_number'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'vendor' => $row['vendor'],
            'invoice' => $row['invoice_number'],
            'date' => $row['invoice_date'],
            'payment_status' => $row['payment_status'],
            'payment_due_date' => $row['payment_due_date'] ?? '',
            'items' => []
        ];
    }
    $grouped[$key]['items'][] = $row;
}

// Set headers for Excel download
$period_text = $month_names[$selected_month] . "_" . $selected_year;
$filename = "Barang_Masuk_" . $period_text . "_" . date('Ymd_His') . ".xls";
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
        th { background-color: #4472C4; color: white; font-weight: bold; }
        .number { mso-number-format: "\#\,\#\#0"; }
        .currency { mso-number-format: "\#\,\#\#0\.00"; }
        .date { mso-number-format: "dd\/mm\/yyyy"; }
        .total-row { background-color: #FFF2CC; font-weight: bold; }
        .grand-total { background-color: #FFD966; font-weight: bold; font-size: 12pt; }
        .type-spare { background-color: #E3F2FD; }
        .type-tool { background-color: #F3E5F5; }
        .type-oil { background-color: #FFF3E0; }
        .status-cash { background-color: #C8E6C9; color: #2E7D32; font-weight: bold; }
        .status-credit { background-color: #FFE0B2; color: #E65100; font-weight: bold; }
        .invoice-header { background-color: #E8F5E9; font-weight: bold; font-size: 12px; }
    </style>
</head>
<body>
    <h2>LAPORAN BARANG MASUK</h2>
    <h3>PERIODE: <?php echo strtoupper($month_names[$selected_month]) . ' ' . $selected_year; ?></h3>
    <p>Tanggal Cetak: <?php echo date('d/m/Y H:i:s'); ?></p>
    <p>User: <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
    <br>
    
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tgl Invoice</th>
                <th>No Invoice</th>
                <th>Vendor</th>
                <th>Status Bayar</th>
                <th>Tgl Tempo</th>
                <th>Rencana Alokasi</th>
                <th>Project</th>
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
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $grand_subtotal = 0;
            $grand_discount = 0;
            $grand_tax = 0;
            $grand_total = 0;
            
            foreach ($grouped as $invoice_key => $invoice_data):
                // Calculate invoice totals
                $invoice_subtotal = 0;
                $invoice_discount = 0;
                $invoice_tax = 0;
                $invoice_total = 0;
                
                // First, print all items for this invoice
                foreach ($invoice_data['items'] as $idx => $row):
                    $invoice_subtotal += $row['subtotal'];
                    $invoice_discount += $row['discount'];
                    $invoice_tax += $row['tax'];
                    $invoice_total += $row['total_price'];
                    
                    // Determine row class based on item type
                    $type_class = '';
                    switch($row['item_type']) {
                        case 'Sparepart': $type_class = 'type-spare'; break;
                        case 'Alat dan Perlengkapan': $type_class = 'type-tool'; break;
                        case 'Oli, Grease, and Coolant': $type_class = 'type-oil'; break;
                    }
                    
                    // Status payment class
                    $status_class = $row['payment_status'] == 'Cash' ? 'status-cash' : 'status-credit';
                    $due_date_display = '';
                    if ($row['payment_status'] == 'Credit' && !empty($row['payment_due_date'])) {
                        $due_date_display = date('d/m/Y', strtotime($row['payment_due_date']));
                    }
            ?>
            <tr class="<?php echo $type_class; ?>">
                <td><?php echo $no++; ?></td>
                <td class="date"><?php echo date('d/m/Y', strtotime($row['invoice_date'])); ?></td>
                <td><?php echo htmlspecialchars($row['invoice_number']); ?></td>
                <td><?php echo htmlspecialchars($row['vendor']); ?></td>
                <td class="<?php echo $status_class; ?>"><?php echo strtoupper($row['payment_status']); ?></td>
                <td class="date"><?php echo $due_date_display; ?></td>
                <td><?php echo htmlspecialchars($row['allocation_plan']); ?></td>
                <td><?php echo htmlspecialchars($row['project']); ?></td>
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
            </tr>
            <?php 
                endforeach;
                
                // Add to grand totals
                $grand_subtotal += $invoice_subtotal;
                $grand_discount += $invoice_discount;
                $grand_tax += $invoice_tax;
                $grand_total += $invoice_total;
            ?>
            <!-- Invoice Total Row -->
            <tr class="total-row">
                <td colspan="14" style="text-align:right;"><strong>TOTAL INVOICE: <?php echo htmlspecialchars($invoice_data['invoice']); ?></strong></td>
                <td class="currency"><strong><?php echo $invoice_subtotal; ?></strong></td>
                <td class="currency"><strong><?php echo $invoice_discount; ?></strong></td>
                <td class="currency"><strong><?php echo $invoice_tax; ?></strong></td>
                <td class="currency"><strong><?php echo $invoice_total; ?></strong></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Grand Total Row -->
            <tr class="grand-total">
                <td colspan="14" style="text-align:right;"><strong>GRAND TOTAL:</strong></td>
                <td class="currency"><strong><?php echo $grand_subtotal; ?></strong></td>
                <td class="currency"><strong><?php echo $grand_discount; ?></strong></td>
                <td class="currency"><strong><?php echo $grand_tax; ?></strong></td>
                <td class="currency"><strong><?php echo $grand_total; ?></strong></td>
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
    <h3>RINGKASAN BERDASARKAN STATUS PEMBAYARAN</h3>
    <table>
        <thead>
            <tr>
                <th>Status Pembayaran</th>
                <th>Jumlah Invoice</th>
                <th>Total Nilai (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $payment_summary = [];
            foreach ($grouped as $invoice_data) {
                $status = $invoice_data['payment_status'];
                if (!isset($payment_summary[$status])) {
                    $payment_summary[$status] = ['count' => 0, 'total' => 0];
                }
                $payment_summary[$status]['count']++;
                foreach ($invoice_data['items'] as $item) {
                    $payment_summary[$status]['total'] += $item['total_price'];
                }
            }
            
            foreach ($payment_summary as $status => $stats):
                $status_class = $status == 'Cash' ? 'status-cash' : 'status-credit';
            ?>
            <tr class="<?php echo $status_class; ?>">
                <td><strong><?php echo strtoupper($status); ?></strong></td>
                <td class="number"><?php echo $stats['count']; ?></td>
                <td class="currency"><?php echo $stats['total']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>