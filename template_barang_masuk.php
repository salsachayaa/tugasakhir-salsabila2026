<?php
// Template CSV untuk Import Barang Masuk
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Template_Barang_Masuk.csv"');

// Output UTF-8 BOM untuk Excel
echo "\xEF\xBB\xBF";

// Header
$header = [
    'Tanggal Invoice',
    'No Invoice',
    'Vendor',
    'Rencana Alokasi',
    'Project',
    'Jenis Barang',
    'Part Number',
    'Nama Barang',
    'Qty',
    'Satuan',
    'Harga',
    'Diskon',
    'Pajak'
];

// Sample data
$samples = [
    [
        '29/12/2024',
        'INV-001',
        'PT Supplier Indonesia',
        'Gudang A',
        'Project Alpha',
        'Sparepart',
        'PN-12345',
        'Baut M8 x 20mm',
        '100',
        'pcs',
        '5000',
        '0',
        '55000'
    ],
    [
        '29/12/2024',
        'INV-001',
        'PT Supplier Indonesia',
        'Gudang A',
        'Project Alpha',
        'Alat dan Perlengkapan',
        'TL-789',
        'Kunci Pas 14mm',
        '5',
        'pcs',
        '75000',
        '5000',
        '38500'
    ],
    [
        '29/12/2024',
        'INV-002',
        'PT Pelumas Jaya',
        'Gudang B',
        'Project Beta',
        'Oli, Grease, and Coolant',
        'OL-456',
        'Oli Mesin SAE 40',
        '20',
        'liter',
        '45000',
        '0',
        '99000'
    ]
];

// Output CSV
$output = fopen('php://output', 'w');

// Write header
fputcsv($output, $header);

// Write sample data
foreach ($samples as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
?>