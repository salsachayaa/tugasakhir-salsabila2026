<?php
require_once 'includes/functions.php';
requireLogin();

$spb = $_GET['spb'] ?? '';
if (empty($spb)) {
    die('SPB tidak ditemukan.');
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT o.*, s.location FROM outgoing_goods o LEFT JOIN inventory_stock s ON o.inventory_stock_id = s.id WHERE o.spb_number = ? ORDER BY o.id ASC");
$stmt->bind_param("s", $spb);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
$conn->close();

if (empty($items)) {
    die('Data barang keluar untuk SPB tersebut tidak ditemukan.');
}

$first = $items[0];
$grand_total = 0;
foreach ($items as $it) { $grand_total += $it['total_price']; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Serah Terima - SPB <?php echo htmlspecialchars($spb); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #1f2937; }
        .sheet { max-width: 820px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn { padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-print { background: #059669; color: #fff; }
        .btn-back { background: #e5e7eb; color: #374151; }

        .header { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid #059669; padding-bottom: 16px; margin-bottom: 24px; }
        .header img { width: 56px; height: 56px; object-fit: contain; }
        .header h1 { font-size: 17px; margin: 0; color: #065f46; }
        .header p { font-size: 11px; margin: 2px 0 0; color: #6b7280; }
        .doc-title { text-align: center; margin-bottom: 24px; }
        .doc-title h2 { font-size: 18px; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-title p { font-size: 12.5px; color: #6b7280; margin: 4px 0 0; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; margin-bottom: 24px; font-size: 12.5px; }
        .info-row { display: flex; gap: 8px; }
        .info-row .label { width: 130px; color: #6b7280; flex-shrink: 0; }
        .info-row .value { font-weight: 600; color: #111827; }

        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 99px; font-size: 11px; font-weight: 700; }
        .status-diterima { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }

        table.items { width: 100%; border-collapse: collapse; font-size: 11.5px; margin-bottom: 24px; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 7px 8px; text-align: left; }
        table.items th { background: #f3f4f6; font-weight: 700; color: #374151; }
        table.items td.num { text-align: right; }
        table.items tfoot td { font-weight: 700; background: #f9fafb; }

        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 50px; text-align: center; font-size: 12px; }
        .signatures .box p.role { color: #6b7280; margin: 0 0 55px; }
        .signatures .box .line { border-top: 1px solid #374151; padding-top: 6px; font-weight: 600; }
        .signatures .box .name-hint { font-size: 10.5px; color: #9ca3af; }

        .notes-box { margin-top: 20px; padding: 12px 14px; background: #f9fafb; border-radius: 6px; font-size: 11.5px; color: #4b5563; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; padding: 20px; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak</button>
    <a href="outgoing_goods.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<div class="sheet">
    <div class="header">
        <img src="images/logo-skds.jpeg" alt="Logo SKDS">
        <div>
            <h1>PT. SARANA KARYA DUA SATU</h1>
            <p>Sistem Manajemen Logistik — Dokumen Serah Terima Barang</p>
        </div>
    </div>

    <div class="doc-title">
        <h2>Surat Serah Terima Barang</h2>
        <p>No. SPB: <?php echo htmlspecialchars($spb); ?></p>
    </div>

    <div class="info-grid">
        <div class="info-row"><span class="label">Tanggal Keluar</span><span class="value">: <?php echo date('d/m/Y', strtotime($first['outgoing_date'])); ?></span></div>
        <div class="info-row"><span class="label">Rencana Alokasi</span><span class="value">: <?php echo htmlspecialchars($first['allocation_plan']); ?></span></div>
        <div class="info-row"><span class="label">Metode Pengiriman</span><span class="value">: <?php echo htmlspecialchars($first['delivery_method'] ?? 'Diambil Langsung'); ?></span></div>
        <div class="info-row"><span class="label">Status</span><span class="value">
            <?php if (($first['delivery_status'] ?? 'DITERIMA') === 'DITERIMA'): ?>
                <span class="status-badge status-diterima">SUDAH DITERIMA</span>
            <?php else: ?>
                <span class="status-badge status-pending">MENUNGGU KONFIRMASI</span>
            <?php endif; ?>
        </span></div>
        <?php if (($first['delivery_method'] ?? '') === 'Diantar Driver/Karyawan'): ?>
        <div class="info-row"><span class="label">Driver / Karyawan</span><span class="value">: <?php echo htmlspecialchars($first['driver_name'] ?: '-'); ?></span></div>
        <div class="info-row"><span class="label">Penanggung Jawab</span><span class="value">: <?php echo htmlspecialchars($first['pic_name'] ?: '-'); ?></span></div>
        <div class="info-row"><span class="label">Penerima</span><span class="value">: <?php echo htmlspecialchars($first['recipient_name'] ?: '-'); ?></span></div>
        <div class="info-row"><span class="label">Lokasi/Jabatan Penerima</span><span class="value">: <?php echo htmlspecialchars($first['recipient_note'] ?: '-'); ?></span></div>
        <?php if (!empty($first['received_at'])): ?>
        <div class="info-row"><span class="label">Waktu Diterima</span><span class="value">: <?php echo date('d/m/Y H:i', strtotime($first['received_at'])); ?></span></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>No</th><th>Part Number</th><th>Nama Barang</th><th>Lokasi</th>
                <th class="num">Qty</th><th>Satuan</th><th class="num">Total (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($items as $it): ?>
            <tr>
                <td><?php echo $no++; ?></td>
                <td><?php echo htmlspecialchars($it['part_number']); ?></td>
                <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                <td><?php echo htmlspecialchars($it['location'] ?? '-'); ?></td>
                <td class="num"><?php echo number_format($it['quantity']); ?></td>
                <td><?php echo htmlspecialchars($it['unit']); ?></td>
                <td class="num"><?php echo number_format($it['total_price'], 0, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="num">TOTAL</td>
                <td class="num"><?php echo number_format($grand_total, 0, ',', '.'); ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($first['received_notes'])): ?>
    <div class="notes-box">
        <strong>Catatan Serah Terima:</strong> <?php echo htmlspecialchars($first['received_notes']); ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($first['notes'])): ?>
    <div class="notes-box">
        <strong>Catatan Barang:</strong> <?php echo htmlspecialchars($first['notes']); ?>
    </div>
    <?php endif; ?>

    <div class="signatures">
        <div class="box">
            <p class="role">Diserahkan oleh<br>(Driver / Karyawan)</p>
            <div class="line"><?php echo htmlspecialchars($first['driver_name'] ?: '.....................'); ?></div>
        </div>
        <div class="box">
            <p class="role">Penanggung Jawab</p>
            <div class="line"><?php echo htmlspecialchars($first['pic_name'] ?: '.....................'); ?></div>
        </div>
        <div class="box">
            <p class="role">Diterima oleh</p>
            <div class="line"><?php echo htmlspecialchars($first['recipient_name'] ?: '.....................'); ?></div>
            <div class="name-hint"><?php echo htmlspecialchars($first['recipient_note'] ?: ''); ?></div>
        </div>
    </div>
</div>

</body>
</html>