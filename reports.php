<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin', 'admin');

$validDate = fn($d, $def) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d)) ? $d : $def;
$dari   = $validDate(get('dari'), date('Y-m-01'));
$sampai = $validDate(get('sampai'), date('Y-m-d'));
if ($sampai < $dari) [$dari, $sampai] = [$sampai, $dari];

[$os, $osp] = outlet_scope('o.outlet_id');
$where = "WHERE o.status <> 'batal' AND o.lunas = 1 AND o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)" . $os;
$params = array_merge([$dari, $sampai], $osp);

/* ---------------- KPI ---------------- */
$kpi = fetchOne(
    "SELECT COUNT(*) AS trx,
            COALESCE(SUM(o.total),0) AS omzet,
            COALESCE(SUM(o.diskon_nilai),0) AS diskon,
            COALESCE(SUM(o.pajak_nilai),0) AS pajak
     FROM orders o $where",
    $params
);

$labaRow = fetchOne(
    "SELECT COALESCE(SUM((oi.harga - oi.harga_modal) * oi.qty),0) AS laba,
            COALESCE(SUM(oi.qty),0) AS item
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     $where",
    $params
);

/* ---------------- Harian ---------------- */
$harian = fetchAll(
    "SELECT DATE(o.created_at) AS tgl, COUNT(*) AS trx, COALESCE(SUM(o.total),0) AS omzet
     FROM orders o $where GROUP BY DATE(o.created_at) ORDER BY tgl",
    $params
);

/* ---------------- Per kategori ---------------- */
$perKategori = fetchAll(
    "SELECT COALESCE(c.nama,'Tanpa Kategori') AS kategori, SUM(oi.qty) AS qty, SUM(oi.subtotal) AS omzet
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     $where
     GROUP BY kategori ORDER BY omzet DESC",
    $params
);

/* ---------------- Produk terlaris ---------------- */
$terlaris = fetchAll(
    "SELECT oi.nama_produk, SUM(oi.qty) AS qty, SUM(oi.subtotal) AS omzet,
            SUM((oi.harga - oi.harga_modal) * oi.qty) AS laba
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     $where
     GROUP BY oi.nama_produk ORDER BY qty DESC LIMIT 10",
    $params
);

/* ---------------- Metode bayar ---------------- */
$perMetode = fetchAll(
    "SELECT o.metode_bayar, COUNT(*) AS trx, COALESCE(SUM(o.total),0) AS omzet
     FROM orders o $where GROUP BY o.metode_bayar ORDER BY omzet DESC",
    $params
);

/* ---------------- Jam sibuk ---------------- */
$perJam = fetchAll(
    "SELECT HOUR(o.created_at) AS jam, COUNT(*) AS trx, COALESCE(SUM(o.total),0) AS omzet
     FROM orders o $where GROUP BY HOUR(o.created_at) ORDER BY jam",
    $params
);
$jamMap = [];
foreach ($perJam as $r) $jamMap[(int)$r['jam']] = $r;
$maxJam = $perJam ? max(1, max(array_column($perJam, 'omzet'))) : 1;

/* ---------------- Export CSV ---------------- */
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_' . $dari . '_sd_' . $sampai . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    csv_row($out, ['Laporan Penjualan KasirKu']);
    csv_row($out, ['Periode', $dari, 'sampai', $sampai]);
    csv_row($out, []);
    csv_row($out, ['Tanggal', 'Jumlah Transaksi', 'Omzet']);
    foreach ($harian as $h) csv_row($out, [$h['tgl'], $h['trx'], $h['omzet']]);
    csv_row($out, []);
    csv_row($out, ['Produk', 'Qty Terjual', 'Omzet', 'Laba']);
    foreach ($terlaris as $t) csv_row($out, [$t['nama_produk'], $t['qty'], $t['omzet'], $t['laba']]);
    csv_row($out, []);
    csv_row($out, ['Metode Bayar', 'Transaksi', 'Omzet']);
    foreach ($perMetode as $m) csv_row($out, [ucfirst($m['metode_bayar']), $m['trx'], $m['omzet']]);
    fclose($out);
    exit;
}

$page_title = 'Laporan Penjualan';
$page_sub   = 'Analisa performa penjualan';
$active     = 'reports.php';
include __DIR__ . '/includes/header.php';

$preset = [
    'Hari ini'      => [date('Y-m-d'), date('Y-m-d')],
    '7 Hari'        => [date('Y-m-d', strtotime('-6 day')), date('Y-m-d')],
    'Bulan ini'     => [date('Y-m-01'), date('Y-m-d')],
    '30 Hari'       => [date('Y-m-d', strtotime('-29 day')), date('Y-m-d')],
];
?>

<div class="card mb-3 no-print">
  <div class="card-body">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div><label class="field-label">Dari</label><input class="input" type="date" name="dari" value="<?= e($dari) ?>"></div>
      <div><label class="field-label">Sampai</label><input class="input" type="date" name="sampai" value="<?= e($sampai) ?>"></div>
      <button class="btn btn-primary" type="submit">Terapkan</button>
      <div style="display:flex;gap:6px;margin-left:auto;flex-wrap:wrap">
        <?php foreach ($preset as $label => $r): ?>
          <a class="btn btn-light btn-sm" href="?dari=<?= $r[0] ?>&sampai=<?= $r[1] ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <a class="btn btn-light btn-sm" href="?dari=<?= e($dari) ?>&sampai=<?= e($sampai) ?>&export=csv">Export CSV</a>
        <button class="btn btn-light btn-sm" type="button" onclick="window.print()">&#128424; Cetak</button>
      </div>
    </form>
  </div>
</div>

<div class="grid grid-4 mb-3">
  <div class="stat"><span class="stat-label">Omzet</span><span class="stat-value"><?= rupiah($kpi['omzet']) ?></span><span class="stat-foot"><?= angka($kpi['trx']) ?> transaksi</span></div>
  <div class="stat accent"><span class="stat-label">Laba Kotor</span><span class="stat-value"><?= rupiah($labaRow['laba']) ?></span><span class="stat-foot">Estimasi dari harga modal</span></div>
  <div class="stat info"><span class="stat-label">Item Terjual</span><span class="stat-value"><?= angka($labaRow['item']) ?></span><span class="stat-foot">Total kuantitas</span></div>
  <div class="stat"><span class="stat-label">Rata-rata Transaksi</span><span class="stat-value"><?= rupiah($kpi['trx'] > 0 ? $kpi['omzet'] / $kpi['trx'] : 0) ?></span><span class="stat-foot">Diskon <?= rupiah($kpi['diskon']) ?></span></div>
</div>

<div class="grid" style="grid-template-columns: 1fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head"><h3>Rekap Harian</h3></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Tanggal</th><th class="text-center">Transaksi</th><th class="text-right">Omzet</th></tr></thead>
        <tbody>
        <?php if (!$harian): ?>
          <tr><td colspan="3" class="text-center muted" style="padding:30px">Tidak ada data.</td></tr>
        <?php else: foreach ($harian as $h): ?>
          <tr><td><?= tanggal_indo($h['tgl'], false) ?></td><td class="text-center mono"><?= angka($h['trx']) ?></td><td class="text-right mono"><?= rupiah($h['omzet']) ?></td></tr>
        <?php endforeach; ?>
          <tr style="background:var(--surface-2);font-weight:700">
            <td>Total</td><td class="text-center mono"><?= angka($kpi['trx']) ?></td><td class="text-right mono"><?= rupiah($kpi['omzet']) ?></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Penjualan per Kategori</h3></div>
    <div class="card-body">
      <?php if (!$perKategori): ?>
        <div class="empty-state"><div class="es-ico">&#128202;</div>Belum ada data.</div>
      <?php else: $maxKat = max(array_column($perKategori, 'omzet')); foreach ($perKategori as $k): ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
            <strong><?= e($k['kategori']) ?></strong>
            <span class="mono muted"><?= rupiah($k['omzet']) ?> &middot; <?= angka($k['qty']) ?> item</span>
          </div>
          <div class="bar"><span style="width:<?= max(4, $k['omzet'] / $maxKat * 100) ?>%"></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="grid mt-3" style="grid-template-columns: 1.4fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head"><h3>10 Produk Terlaris</h3></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>#</th><th>Produk</th><th class="text-center">Qty</th><th class="text-right">Omzet</th><th class="text-right">Laba</th></tr></thead>
        <tbody>
        <?php if (!$terlaris): ?>
          <tr><td colspan="5" class="text-center muted" style="padding:30px">Tidak ada data.</td></tr>
        <?php else: foreach ($terlaris as $i => $t): ?>
          <tr>
            <td class="mono muted"><?= $i + 1 ?></td>
            <td style="font-weight:600"><?= e($t['nama_produk']) ?></td>
            <td class="text-center mono"><?= angka($t['qty']) ?></td>
            <td class="text-right mono"><?= rupiah($t['omzet']) ?></td>
            <td class="text-right mono" style="color:var(--primary)"><?= rupiah($t['laba']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card mb-3">
      <div class="card-head"><h3>Metode Pembayaran</h3></div>
      <div class="card-body">
        <?php if (!$perMetode): ?>
          <div class="empty-state" style="padding:20px">Belum ada data.</div>
        <?php else: $maxMet = max(array_column($perMetode, 'omzet')); foreach ($perMetode as $m): ?>
          <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
              <strong><?= e(ucfirst($m['metode_bayar'])) ?></strong>
              <span class="mono muted"><?= rupiah($m['omzet']) ?> (<?= angka($m['trx']) ?>x)</span>
            </div>
            <div class="bar accent"><span style="width:<?= max(4, $m['omzet'] / $maxMet * 100) ?>%"></span></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Jam Sibuk</h3></div>
      <div class="card-body">
        <div style="display:flex;align-items:flex-end;gap:3px;height:110px">
          <?php for ($j = 6; $j <= 23; $j++): $v = $jamMap[$j]['omzet'] ?? 0; $h = max(3, $v / $maxJam * 100); ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px" title="<?= $j ?>:00 - <?= rupiah($v) ?>">
              <div style="width:100%;height:<?= $h ?>px;background:var(--primary);border-radius:4px 4px 0 0;opacity:<?= $v > 0 ? 1 : .25 ?>"></div>
              <div style="font-size:9.5px;color:var(--muted)"><?= $j ?></div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
