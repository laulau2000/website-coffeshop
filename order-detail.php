<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)get('id', 0);
[$os, $osp] = outlet_scope('o.outlet_id');
$order = fetchOne(
    "SELECT o.*, rt.nama AS meja_nama, us.nama AS kasir_nama, ot.nama AS outlet_nama,
            ot.alamat AS outlet_alamat, ot.telepon AS outlet_telepon
     FROM orders o
     LEFT JOIN resto_tables rt ON rt.id = o.table_id
     LEFT JOIN users us ON us.id = o.user_id
     LEFT JOIN outlets ot ON ot.id = o.outlet_id
     WHERE o.id = ?" . $os,
    array_merge([$id], $osp)
);
if (!$order) {
    set_flash('danger', 'Transaksi tidak ditemukan.');
    redirect('orders.php');
}
$items = fetchAll('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
$setting = outlet_settings((int)$order['outlet_id']);
$lebar = (int)$setting['lebar_struk'] === 80 ? 80 : 58;
$batalOleh = $order['batal_oleh'] ? fetchCol('SELECT nama FROM users WHERE id = ?', [$order['batal_oleh']]) : null;
$autoPrint = (int)get('print', 0) === 1;
$tiket = (int)get('tiket', 0) === 1;
$batchTiket = max(0, (int)get('batch', 0));
$nextBill = (int)get('next_bill', 0);
if ($tiket) {
    $items = array_values(array_filter($items, fn($it) => !$batchTiket || (int)$it['batch'] === $batchTiket));
    $autoPrint = true;
}

$tipeLabel = ['dine_in' => 'Dine In', 'take_away' => 'Take Away', 'delivery' => 'Delivery'][$order['tipe']] ?? $order['tipe'];
$metodeLabel = ['cash' => 'Tunai', 'qris' => 'QRIS', 'debit' => 'Debit', 'kredit' => 'Kredit', 'ewallet' => 'E-Wallet', 'transfer' => 'Transfer'][$order['metode_bayar']] ?? $order['metode_bayar'];

$page_title = 'Transaksi ' . $order['kode'];
$page_sub   = tanggal_indo($order['created_at']);
$active     = 'orders.php';
include __DIR__ . '/includes/header.php';
?>

<div class="no-print" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <a href="<?= e(BASE_URL) ?>orders.php" class="btn btn-light">&larr; Kembali</a>
  <button class="btn btn-primary" type="button" onclick="window.print()"><?= icon('printer', 16) ?> Cetak Struk</button>
  <a href="<?= e(BASE_URL) ?>pos.php" class="btn btn-accent">Transaksi Baru</a>
  <?php if (!$tiket): ?><a href="?id=<?= (int)$order['id'] ?>&tiket=1" class="btn btn-light" target="_blank">Tiket Dapur</a><?php endif; ?>
  <?php if ($nextBill): ?><a href="<?= e(BASE_URL) ?>bill.php?id=<?= $nextBill ?>" class="btn btn-primary">Lanjut ke sisa bill &rarr;</a><?php endif; ?>
  <?php if (!$order['lunas'] && $order['status'] !== 'batal'): ?><a href="<?= e(BASE_URL) ?>bill.php?id=<?= (int)$order['id'] ?>" class="btn btn-primary">Kelola / Bayar Bill</a><?php endif; ?>
</div>
<?php if ($tiket): ?>
<div class="card" style="max-width:760px;margin:0 auto">
  <div class="card-body">
    <div class="receipt receipt-<?= $lebar ?> kitchen-ticket">
      <div class="r-center"><strong style="font-size:16px">TIKET DAPUR / BAR</strong><br>
        <span style="font-size:18px;font-weight:800"><?= e($order['meja_nama'] ? strtoupper(meja_label($order['meja_nama'])) : ($order['nama_pelanggan'] ?: strtoupper($tipeLabel))) ?></span></div>
      <div class="r-line"></div>
      <table>
        <tr><td>No</td><td class="text-right"><?= e($order['kode']) ?><?= $batchTiket > 1 ? ' (+' . $batchTiket . ')' : '' ?></td></tr>
        <tr><td>Waktu</td><td class="text-right"><?= e(date('d/m H:i')) ?></td></tr>
        <tr><td>Tipe</td><td class="text-right"><?= e($tipeLabel) ?></td></tr>
      </table>
      <?php foreach (['dapur' => 'DAPUR', 'bar' => 'BAR'] as $stk => $lbl): $grp = array_filter($items, fn($it) => $it['stasiun'] === $stk); if (!$grp) continue; ?>
        <div class="r-line"></div>
        <div style="font-weight:800">== <?= $lbl ?> ==</div>
        <table>
          <?php foreach ($grp as $it): ?>
            <tr><td style="width:34px;font-weight:800;font-size:14px"><?= (int)$it['qty'] ?>x</td><td style="font-size:14px;font-weight:700"><?= e($it['nama_produk']) ?></td></tr>
            <?php if ($it['varian']): ?><tr><td></td><td><?= e($it['varian']) ?></td></tr><?php endif; ?>
            <?php if ($it['catatan']): ?><tr><td></td><td><strong>* <?= e($it['catatan']) ?></strong></td></tr><?php endif; ?>
          <?php endforeach; ?>
        </table>
      <?php endforeach; ?>
      <div class="r-line"></div>
    </div>
  </div>
</div>
<?php else: ?>

<?php if ($order['status'] === 'batal'): ?>
  <div class="alert alert-danger no-print" style="max-width:760px;margin:0 auto 16px">
    Transaksi DIBATALKAN<?= $order['batal_at'] ? ' pada ' . e(tanggal_indo($order['batal_at'])) : '' ?><?= $batalOleh ? ' oleh ' . e($batalOleh) : '' ?>.
    <?= $order['batal_alasan'] ? 'Alasan: ' . e($order['batal_alasan']) : '' ?>
  </div>
<?php endif; ?>

<div class="card" style="max-width:760px;margin:0 auto">
  <div class="card-body">
    <div class="receipt receipt-<?= $lebar ?>" id="receipt">
      <div class="r-center">
        <?php if ($setting['logo']): ?><img src="<?= e(UPLOAD_URL . $setting['logo']) ?>" alt="" class="r-logo"><br><?php endif; ?>
        <strong style="font-size:15px"><?= e($setting['nama_resto'] ?: $order['outlet_nama']) ?></strong><br>
        <?php $alamat = $setting['alamat'] ?: $order['outlet_alamat']; $telp = $setting['telepon'] ?: $order['outlet_telepon']; ?>
        <?php if ($alamat): ?><?= e($alamat) ?><br><?php endif; ?>
        <?php if ($telp): ?>Telp: <?= e($telp) ?><?php endif; ?>
      </div>
      <?php if ($order['status'] === 'batal'): ?><div class="r-center" style="font-weight:800;margin-top:6px">*** BATAL ***</div><?php endif; ?>
      <?php if (!$order['lunas'] && $order['status'] !== 'batal'): ?><div class="r-center" style="font-weight:800;margin-top:6px">*** TAGIHAN - BELUM DIBAYAR ***</div><?php endif; ?>
      <div class="r-line"></div>
      <table>
        <tr><td>No</td><td class="text-right"><?= e($order['kode']) ?></td></tr>
        <tr><td>Tanggal</td><td class="text-right"><?= tanggal_indo($order['created_at']) ?></td></tr>
        <tr><td>Kasir</td><td class="text-right"><?= e($order['kasir_nama'] ?: '-') ?></td></tr>
        <tr><td>Tipe</td><td class="text-right"><?= e($tipeLabel) ?><?= $order['meja_nama'] ? ' / ' . e($order['meja_nama']) : '' ?></td></tr>
        <?php if ($order['nama_pelanggan']): ?>
        <tr><td>Pelanggan</td><td class="text-right"><?= e($order['nama_pelanggan']) ?></td></tr>
        <?php endif; ?>
      </table>
      <div class="r-line"></div>
      <table>
        <?php foreach ($items as $it): ?>
          <tr><td colspan="2"><?= e($it['nama_produk']) ?></td></tr>
          <?php if ($it['varian']): ?><tr><td colspan="2" style="font-size:11px;color:#555"><?= e($it['varian']) ?></td></tr><?php endif; ?>
          <?php if ($it['catatan']): ?><tr><td colspan="2" style="font-size:11px;color:#555">* <?= e($it['catatan']) ?></td></tr><?php endif; ?>
          <tr>
            <td><?= angka($it['qty']) ?> x <?= angka($it['harga']) ?></td>
            <td class="text-right"><?= angka($it['subtotal']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="r-line"></div>
      <table>
        <tr><td>Subtotal</td><td class="text-right"><?= angka($order['subtotal']) ?></td></tr>
        <?php if ((float)$order['diskon_nilai'] > 0): ?>
        <tr><td>Diskon (<?= angka($order['diskon_persen']) ?>%)</td><td class="text-right">-<?= angka($order['diskon_nilai']) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$order['service_nilai'] > 0): ?>
        <tr><td>Service (<?= angka($order['service_persen']) ?>%)</td><td class="text-right"><?= angka($order['service_nilai']) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$order['pajak_nilai'] > 0): ?>
        <tr><td>Pajak (<?= angka($order['pajak_persen']) ?>%)</td><td class="text-right"><?= angka($order['pajak_nilai']) ?></td></tr>
        <?php endif; ?>
        <tr class="r-total"><td>Total</td><td class="text-right"><?= angka($order['total']) ?></td></tr>
        <?php if ($order['lunas']): ?>
        <tr><td>Bayar (<?= e($metodeLabel) ?>)</td><td class="text-right"><?= angka($order['bayar']) ?></td></tr>
        <tr><td>Kembalian</td><td class="text-right"><?= angka($order['kembalian']) ?></td></tr>
        <?php endif; ?>
      </table>
      <div class="r-line"></div>
      <div class="r-center" style="font-size:11px">
        <?= e($setting['footer_struk'] ?: 'Terima kasih atas kunjungan Anda') ?><br>
        <?= e(ucwords(terbilang((int)round($order['total'])))) ?> Rupiah
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
