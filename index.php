<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$u = current_user();

/* ------------------ Ringkasan KPI ------------------ */
[$s, $sp] = outlet_scope('o.outlet_id');

$hariIni = fetchOne(
    "SELECT COUNT(*) AS trx, COALESCE(SUM(o.total),0) AS omzet
     FROM orders o
     WHERE o.status <> 'batal' AND o.lunas = 1 AND o.created_at >= CURDATE() AND o.created_at < CURDATE() + INTERVAL 1 DAY" . $s,
    $sp
);
$trxHariIni  = (int)$hariIni['trx'];
$omzetHariIni = (float)$hariIni['omzet'];
$rataRata    = $trxHariIni > 0 ? $omzetHariIni / $trxHariIni : 0;

$kemarin = fetchOne(
    "SELECT COALESCE(SUM(o.total),0) AS omzet FROM orders o
     WHERE o.status <> 'batal' AND o.lunas = 1 AND o.created_at >= CURDATE() - INTERVAL 1 DAY AND o.created_at < CURDATE()" . $s,
    $sp
);
$delta = (float)$kemarin['omzet'] > 0
    ? (($omzetHariIni - (float)$kemarin['omzet']) / (float)$kemarin['omzet']) * 100
    : ($omzetHariIni > 0 ? 100 : 0);

[$ps, $psp] = outlet_scope('p.outlet_id');
$produkAktif = (int)fetchCol('SELECT COUNT(*) FROM products p WHERE p.aktif = 1' . $ps, $psp);

[$ts, $tsp] = outlet_scope('outlet_id');
$mejaKosong = (int)fetchCol("SELECT COUNT(*) FROM resto_tables WHERE status = 'kosong'" . $ts, $tsp);
$mejaTotal  = (int)fetchCol('SELECT COUNT(*) FROM resto_tables WHERE 1=1' . $ts, $tsp);

/* ------------------ Penjualan 7 hari ------------------ */
$rows7 = fetchAll(
    "SELECT DATE(o.created_at) AS tgl, COALESCE(SUM(o.total),0) AS omzet, COUNT(*) AS trx
     FROM orders o
     WHERE o.status <> 'batal' AND o.lunas = 1 AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)" . $s . "
     GROUP BY DATE(o.created_at)",
    $sp
);
$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $chart[$d] = ['omzet' => 0, 'trx' => 0];
}
foreach ($rows7 as $r) {
    if (isset($chart[$r['tgl']])) {
        $chart[$r['tgl']] = ['omzet' => (float)$r['omzet'], 'trx' => (int)$r['trx']];
    }
}
$maxOmzet = max(1, max(array_column($chart, 'omzet')));

/* ------------------ Produk terlaris (7 hari) ------------------ */
$terlaris = fetchAll(
    "SELECT oi.nama_produk, SUM(oi.qty) AS qty, SUM(oi.subtotal) AS omzet
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status <> 'batal' AND o.lunas = 1 AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $s . "
     GROUP BY oi.nama_produk ORDER BY qty DESC LIMIT 5",
    $sp
);
$maxQty = $terlaris ? max(array_column($terlaris, 'qty')) : 1;

/* ------------------ Transaksi terbaru ------------------ */
$terbaru = fetchAll(
    "SELECT o.*, rt.nama AS meja_nama, us.nama AS kasir_nama
     FROM orders o
     LEFT JOIN resto_tables rt ON rt.id = o.table_id
     LEFT JOIN users us ON us.id = o.user_id
     WHERE 1=1" . $s . "
     ORDER BY o.id DESC LIMIT 8",
    $sp
);

/* ------------------ Stok menipis ------------------ */
$stokTipis = fetchAll('SELECT nama, stok, satuan FROM products p WHERE p.aktif = 1 AND p.deleted_at IS NULL AND p.lacak_stok = 1 AND p.stok <= 10' . $ps . ' ORDER BY p.stok ASC LIMIT 5', $psp);

$page_title = 'Dashboard';
$page_sub   = 'Ringkasan performa ' . ($u['role'] === 'superadmin' && active_outlet_id() === null ? 'seluruh cabang' : ($u['outlet_nama'] ?: 'cabang'));
$active     = 'index.php';
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4 mb-3">
  <div class="stat">
    <span class="stat-label">Omzet Hari Ini</span>
    <span class="stat-value"><?= rupiah($omzetHariIni) ?></span>
    <span class="stat-foot">
      <?php if ($delta >= 0): ?>
        <span style="color:var(--primary)">&#9650; <?= angka(abs($delta), 1) ?>%</span> vs kemarin
      <?php else: ?>
        <span style="color:var(--danger)">&#9660; <?= angka(abs($delta), 1) ?>%</span> vs kemarin
      <?php endif; ?>
    </span>
  </div>
  <div class="stat accent">
    <span class="stat-label">Transaksi</span>
    <span class="stat-value"><?= angka($trxHariIni) ?></span>
    <span class="stat-foot">Rata-rata <?= rupiah($rataRata) ?></span>
  </div>
  <div class="stat info">
    <span class="stat-label">Menu Aktif</span>
    <span class="stat-value"><?= angka($produkAktif) ?></span>
    <span class="stat-foot">Produk siap jual</span>
  </div>
  <div class="stat">
    <span class="stat-label">Meja Kosong</span>
    <span class="stat-value"><?= angka($mejaKosong) ?>/<?= angka($mejaTotal) ?></span>
    <span class="stat-foot">Ketersediaan meja</span>
  </div>
</div>

<div class="grid" style="grid-template-columns: 1.6fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head">
      <h3>Penjualan 7 Hari Terakhir</h3>
      <span class="badge badge-success" style="margin-left:auto">Total <?= rupiah(array_sum(array_column($chart, 'omzet'))) ?></span>
    </div>
    <div class="card-body">
      <div style="display:flex;align-items:flex-end;gap:12px;height:190px">
        <?php foreach ($chart as $tgl => $c): $h = max(4, ($c['omzet'] / $maxOmzet) * 160); ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px">
            <div class="mono" style="font-size:10.5px;color:var(--muted)"><?= $c['omzet'] > 0 ? angka($c['omzet'] / 1000) . 'k' : '' ?></div>
            <div title="<?= e(rupiah($c['omzet'])) ?>"
                 style="width:100%;height:<?= $h ?>px;border-radius:8px 8px 4px 4px;background:linear-gradient(180deg,#2ea87c,#157f5f)"></div>
            <div style="font-size:11px;color:var(--muted)"><?= date('D', strtotime($tgl)) ?><br><?= date('d/m', strtotime($tgl)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Produk Terlaris</h3><span class="muted" style="margin-left:auto;font-size:12px">7 hari</span></div>
    <div class="card-body">
      <?php if (!$terlaris): ?>
        <div class="empty-state"><div class="es-ico"><?= icon('chart', 34) ?></div>Belum ada penjualan.</div>
      <?php else: foreach ($terlaris as $t): ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
            <strong><?= e($t['nama_produk']) ?></strong>
            <span class="mono muted"><?= angka($t['qty']) ?>x</span>
          </div>
          <div class="bar accent"><span style="width:<?= max(6, ($t['qty'] / $maxQty) * 100) ?>%"></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="grid mt-3" style="grid-template-columns: 1.6fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head">
      <h3>Transaksi Terbaru</h3>
      <a href="<?= e(BASE_URL) ?>orders.php" class="btn btn-light btn-sm" style="margin-left:auto">Lihat semua</a>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Kode</th><th>Pelanggan/Meja</th><th>Kasir</th><th>Status</th><th class="text-right">Total</th></tr></thead>
        <tbody>
        <?php if (!$terbaru): ?>
          <tr><td colspan="5" class="text-center muted" style="padding:30px">Belum ada transaksi.</td></tr>
        <?php else: foreach ($terbaru as $o): ?>
          <tr>
            <td class="mono"><a href="<?= e(BASE_URL) ?>order-detail.php?id=<?= (int)$o['id'] ?>"><?= e($o['kode']) ?></a></td>
            <td><?= e($o['nama_pelanggan'] ?: ($o['meja_nama'] ?: ucfirst(str_replace('_', ' ', $o['tipe'])))) ?></td>
            <td class="muted"><?= e($o['kasir_nama'] ?: '-') ?></td>
            <td>
              <?php
                $map = ['pending'=>'badge-warning','proses'=>'badge-info','selesai'=>'badge-success','batal'=>'badge-danger'];
                echo '<span class="badge ' . ($map[$o['status']] ?? '') . '">' . e(ucfirst($o['status'])) . '</span>';
              ?>
            </td>
            <td class="text-right mono"><?= rupiah($o['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Stok Menipis</h3></div>
    <div class="card-body">
      <?php if (!$stokTipis): ?>
        <div class="empty-state"><div class="es-ico"><?= icon('check', 34) ?></div>Semua stok aman.</div>
      <?php else: foreach ($stokTipis as $st): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px dashed var(--border)">
          <span style="font-size:13px"><?= e($st['nama']) ?></span>
          <span class="badge <?= $st['stok'] <= 5 ? 'badge-danger' : 'badge-warning' ?>"><?= angka($st['stok']) ?> <?= e($st['satuan']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-3 mt-3">
  <a href="<?= e(BASE_URL) ?>pos.php" class="card card-pad" style="text-decoration:none;color:inherit">
    <div class="icon-tile"><?= icon('pos', 22) ?></div>
    <h3 style="margin:8px 0 2px">Mulai Transaksi</h3>
    <p style="margin:0;font-size:12.5px">Buka halaman kasir untuk melayani pesanan baru.</p>
  </a>
  <a href="<?= e(BASE_URL) ?>tables.php" class="card card-pad" style="text-decoration:none;color:inherit">
    <div class="icon-tile"><?= icon('table', 22) ?></div>
    <h3 style="margin:8px 0 2px">Kelola Meja</h3>
    <p style="margin:0;font-size:12.5px">Pantau status meja &amp; pesanan berjalan.</p>
  </a>
  <a href="<?= e(BASE_URL) ?>reports.php" class="card card-pad" style="text-decoration:none;color:inherit">
    <div class="icon-tile"><?= icon('chart', 22) ?></div>
    <h3 style="margin:8px 0 2px">Lihat Laporan</h3>
    <p style="margin:0;font-size:12.5px">Analisa penjualan harian &amp; bulanan.</p>
  </a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
