<?php
header("Content-type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Barang_Masuk.xls");

include 'koneksi.php';
?>

<table border="1">
<tr>
  <th>No</th>
  <th>Tgl Invoice</th>
  <th>No Invoice</th>
  <th>Vendor</th>
  <th>Rencana Alokasi</th>
  <th>Project</th>
  <th>Part Number</th>
  <th>Nama Barang</th>
  <th>Qty</th>
  <th>Satuan</th>
  <th>Harga</th>
  <th>Total</th>
</tr>

<?php
$no=1;
$data = mysqli_query($conn,"SELECT * FROM barang_masuk");
while($d=mysqli_fetch_array($data)){
?>
<tr>
<td><?= $no++ ?></td>
<td><?= $d['tgl_invoice'] ?></td>
<td><?= $d['no_invoice'] ?></td>
<td><?= $d['vendor'] ?></td>
<td><?= $d['rencana_alokasi'] ?></td>
<td><?= $d['project'] ?></td>
<td><?= $d['part_number'] ?></td>
<td><?= $d['nama_barang'] ?></td>
<td><?= $d['qty'] ?></td>
<td><?= $d['satuan'] ?></td>
<td><?= $d['harga'] ?></td>
<td><?= $d['total'] ?></td>
</tr>
<?php } ?>
</table>
    