<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin', 'admin', 'kasir');
require_outlet();
[$os, $osp] = outlet_scope('o.outlet_id');
$bills = fetchAll(
    "SELECT o.*, rt.nama meja, us.nama kasir,
            (SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id = o.id) jml_item,
            (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND status_dapur IN ('baru','dimasak')) belum_siap
     FROM orders o LEFT JOIN resto_tables rt ON rt.id = o.table_id LEFT JOIN users us ON us.id = o.user_id
     WHERE o.lunas = 0 AND o.status <> 'batal'" . $os . " ORDER BY o.created_at",
    $osp
);
$page_title = 'Open Bill';
$page_sub   = 'Pesanan yang belum dibayar';
$active     = 'bills.php';
include __DIR__ . '/includes/header.php';
?>
<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <a class="btn btn-primary" href="<?= e(BASE_URL) ?>pos.php">+ Pesanan Baru</a>
  <span class="hint" style="align-self:center">Buat open bill dari halaman Kasir dengan tombol <strong>Simpan Bill</strong>.</span>
</div>
<?php if (!$bills): ?>
  <div class="card"><div class="empty-state"><div class="es-ico"><?= icon('check', 34) ?></div>Tidak ada bill yang belum dibayar.</div></div>
<?php else: ?>
<div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(250px, 1fr))">
  <?php foreach ($bills as $b): ?>
    <a class="card card-pad bill-card" href="<?= e(BASE_URL) ?>bill.php?id=<?= (int)$b['id'] ?>">
      <div style="display:flex;justify-content:space-between;gap:8px">
        <strong style="font-size:16px"><?= e($b['meja'] ? meja_label($b['meja']) : ($b['nama_pelanggan'] ?: 'Tanpa nama')) ?></strong>
        <span class="muted" style="font-size:12px"><?= e(date('H:i', strtotime($b['created_at']))) ?></span>
      </div>
      <div class="muted" style="font-size:12px"><?= e($b['kode']) ?><?= $b['meja'] && $b['nama_pelanggan'] ? ' &middot; ' . e($b['nama_pelanggan']) : '' ?> &middot; <?= e($b['kasir'] ?: '-') ?></div>
      <div style="display:flex;justify-content:space-between;align-items:end;margin-top:12px">
        <div><span class="badge"><?= angka($b['jml_item']) ?> item</span>
          <?php if ($b['belum_siap']): ?><span class="badge badge-warning"><?= angka($b['belum_siap']) ?> diproses dapur</span><?php endif; ?></div>
        <strong class="mono"><?= rupiah($b['total']) ?></strong>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
