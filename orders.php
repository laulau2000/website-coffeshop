<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

/* ---------------- Aksi ubah status ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $oid    = (int)post('order_id', 0);
    $action = (string)post('action', '');
    $back   = 'orders.php?' . http_build_query(array_intersect_key($_GET, array_flip(['dari', 'sampai', 'status', 'q', 'page'])));
    [$os, $osp] = outlet_scope('outlet_id');
    $pdo = db();
    $pdo->beginTransaction();
    $ord = fetchOne('SELECT * FROM orders WHERE id = ?' . $os . ' FOR UPDATE', array_merge([$oid], $osp));
    if (!$ord) {
        $pdo->rollBack();
        set_flash('danger', 'Transaksi tidak ditemukan.');
        redirect($back);
    }
    if ($action === 'batal') {
        $alasan = mb_substr(trim((string)post('alasan', '')), 0, 255);
        if (!can_manage()) {
            $pdo->rollBack();
            set_flash('danger', 'Hanya admin/pemilik yang dapat membatalkan transaksi.');
        } elseif ($ord['status'] === 'batal') {
            $pdo->rollBack();
            set_flash('warning', 'Transaksi sudah dibatalkan sebelumnya.');
        } elseif ($alasan === '') {
            $pdo->rollBack();
            set_flash('danger', 'Alasan pembatalan wajib diisi.');
        } else {
            q('UPDATE orders SET status = "batal", batal_oleh = ?, batal_alasan = ?, batal_at = NOW() WHERE id = ?',
              [current_user()['id'], $alasan, $oid]);
            // Kembalikan stok
            q('UPDATE products p JOIN (SELECT product_id, SUM(qty) q FROM order_items WHERE order_id = ? AND product_id IS NOT NULL GROUP BY product_id) x
               ON x.product_id = p.id SET p.stok = p.stok + x.q WHERE p.lacak_stok = 1', [$oid]);
            if ($ord['table_id'] && $ord['status'] === 'proses') {
                q("UPDATE resto_tables SET status = 'kosong' WHERE id = ? AND outlet_id = ?", [$ord['table_id'], $ord['outlet_id']]);
            }
            $pdo->commit();
            log_activity('batal', $ord['kode'] . ' dibatalkan (Rp ' . number_format((float)$ord['total'], 0, ',', '.') . '). Alasan: ' . $alasan, (int)$ord['outlet_id']);
            set_flash('warning', 'Transaksi ' . $ord['kode'] . ' dibatalkan dan stok dikembalikan.');
        }
    } elseif ($action === 'selesai' && in_array($ord['status'], ['pending', 'proses'], true) && $ord['lunas']) {
        q('UPDATE orders SET status = "selesai" WHERE id = ?', [$oid]);
        if ($ord['table_id']) {
            $masihAda = (int)fetchCol("SELECT COUNT(*) FROM orders WHERE table_id = ? AND status IN ('pending','proses') AND id <> ?", [$ord['table_id'], $oid]);
            if (!$masihAda) q("UPDATE resto_tables SET status = 'kosong' WHERE id = ? AND outlet_id = ?", [$ord['table_id'], $ord['outlet_id']]);
        }
        $pdo->commit();
        set_flash('success', 'Pesanan ' . $ord['kode'] . ' selesai. Meja dikosongkan.');
    } else {
        $pdo->rollBack();
        set_flash('danger', 'Aksi tidak valid.');
    }
    redirect($back);
}

/* ---------------- Filter ---------------- */
$validDate = fn($d, $def) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d)) ? $d : $def;
$dari   = $validDate(get('dari'), date('Y-m-d'));
$sampai = $validDate(get('sampai'), date('Y-m-d'));
if ($sampai < $dari) [$dari, $sampai] = [$sampai, $dari];
$status = in_array(get('status', ''), ['selesai', 'pending', 'proses', 'batal'], true) ? get('status') : '';
$cari   = mb_substr(trim((string)get('q', '')), 0, 60);
$perPage = 50;
$page   = max(1, (int)get('page', 1));

$w = ['o.created_at >= ?', 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$p = [$dari, $sampai];
if ($status !== '') { $w[] = 'o.status = ?'; $p[] = $status; }
if ($cari !== '')   { $w[] = '(o.kode LIKE ? OR o.nama_pelanggan LIKE ?)'; $p[] = "%$cari%"; $p[] = "%$cari%"; }
[$os, $osp] = outlet_scope('o.outlet_id');
$p = array_merge($p, $osp);
$where = 'WHERE ' . implode(' AND ', $w) . $os;

$ringkas = fetchOne(
    "SELECT COUNT(*) AS trx,
            SUM(o.status <> 'batal' AND o.lunas = 1) AS trx_valid,
            COALESCE(SUM(CASE WHEN o.status <> 'batal' AND o.lunas = 1 THEN o.total ELSE 0 END),0) AS omzet
     FROM orders o $where",
    $p
);
$totalRows = (int)$ringkas['trx'];
$pages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $pages);

if (get('export') === 'csv' && can_manage()) {
    $all = fetchAll(
        "SELECT o.kode, o.created_at, ot.nama outlet, us.nama kasir, o.tipe, rt.nama meja, o.nama_pelanggan, o.status, o.metode_bayar,
                o.subtotal, o.diskon_nilai, o.service_nilai, o.pajak_nilai, o.total, o.batal_alasan
         FROM orders o LEFT JOIN outlets ot ON ot.id = o.outlet_id LEFT JOIN users us ON us.id = o.user_id
         LEFT JOIN resto_tables rt ON rt.id = o.table_id $where ORDER BY o.id", $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transaksi_' . $dari . '_' . $sampai . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    csv_row($out, ['Kode', 'Waktu', 'Cabang', 'Kasir', 'Tipe', 'Meja', 'Pelanggan', 'Status', 'Metode', 'Subtotal', 'Diskon', 'Service', 'Pajak', 'Total', 'Alasan Batal']);
    foreach ($all as $r) csv_row($out, array_values($r));
    fclose($out);
    exit;
}

$orders = fetchAll(
    "SELECT o.*, rt.nama AS meja_nama, us.nama AS kasir_nama
     FROM orders o
     LEFT JOIN resto_tables rt ON rt.id = o.table_id
     LEFT JOIN users us ON us.id = o.user_id
     $where
     ORDER BY o.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $p
);
$qs = fn(array $extra) => e(BASE_URL . 'orders.php?' . http_build_query(array_merge(['dari' => $dari, 'sampai' => $sampai, 'status' => $status, 'q' => $cari], $extra)));

$page_title = 'Transaksi';
$page_sub   = 'Riwayat & pengelolaan pesanan';
$active     = 'orders.php';
include __DIR__ . '/includes/header.php';
?>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="form-row" style="grid-template-columns: repeat(5, 1fr); align-items:end">
      <div>
        <label class="field-label">Dari Tanggal</label>
        <input class="input" type="date" name="dari" value="<?= e($dari) ?>">
      </div>
      <div>
        <label class="field-label">Sampai Tanggal</label>
        <input class="input" type="date" name="sampai" value="<?= e($sampai) ?>">
      </div>
      <div>
        <label class="field-label">Status</label>
        <select class="select" name="status">
          <option value="">Semua Status</option>
          <?php foreach (['selesai', 'pending', 'proses', 'batal'] as $st): ?>
            <option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="field-label">Cari</label>
        <input class="input" type="text" name="q" value="<?= e($cari) ?>" placeholder="kode / nama pelanggan">
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary btn-block" type="submit">Filter</button>
        <a class="btn btn-light" href="<?= e(BASE_URL) ?>orders.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="grid grid-3 mb-3">
  <div class="stat"><span class="stat-label">Jumlah Transaksi</span><span class="stat-value"><?= angka($ringkas['trx']) ?></span></div>
  <div class="stat accent"><span class="stat-label">Omzet (tanpa batal)</span><span class="stat-value"><?= rupiah($ringkas['omzet']) ?></span></div>
  <div class="stat info"><span class="stat-label">Rata-rata / Transaksi</span><span class="stat-value"><?= rupiah($ringkas['trx_valid'] > 0 ? $ringkas['omzet'] / $ringkas['trx_valid'] : 0) ?></span></div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Daftar Transaksi</h3>
    <?php if (can_manage()): ?><a href="<?= $qs(['export' => 'csv']) ?>" class="btn btn-light btn-sm" style="margin-left:auto">Export CSV</a><?php endif; ?>
    <a href="<?= e(BASE_URL) ?>pos.php" class="btn btn-primary btn-sm"<?= can_manage() ? '' : ' style="margin-left:auto"' ?>>+ Transaksi Baru</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Kode</th><th>Waktu</th><th>Pelanggan / Meja</th><th>Kasir</th>
          <th>Tipe</th><th>Bayar</th><th>Status</th><th class="text-right">Total</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="9" class="text-center muted" style="padding:36px">Tidak ada transaksi pada periode ini.</td></tr>
      <?php else: foreach ($orders as $o): ?>
        <tr>
          <td class="mono"><a href="<?= e(BASE_URL) ?>order-detail.php?id=<?= (int)$o['id'] ?>"><?= e($o['kode']) ?></a></td>
          <td class="muted nowrap"><?= tanggal_indo($o['created_at']) ?></td>
          <td><?= e($o['nama_pelanggan'] ?: ($o['meja_nama'] ?: '-')) ?></td>
          <td class="muted"><?= e($o['kasir_nama'] ?: '-') ?></td>
          <td><span class="badge"><?= e(ucfirst(str_replace('_', ' ', $o['tipe']))) ?></span></td>
          <td><span class="badge badge-info"><?= e(ucfirst($o['metode_bayar'])) ?></span></td>
          <td>
            <?php $map = ['pending'=>'badge-warning','proses'=>'badge-accent','selesai'=>'badge-success','batal'=>'badge-danger']; ?>
            <span class="badge <?= $map[$o['status']] ?? '' ?>"><?= e(ucfirst($o['status'])) ?></span>
            <?php if (!$o['lunas'] && $o['status'] !== 'batal'): ?><span class="badge badge-warning">Belum bayar</span><?php endif; ?>
          </td>
          <td class="text-right mono"><?= rupiah($o['total']) ?></td>
          <td class="text-right nowrap">
            <?php if (!$o['lunas'] && $o['status'] !== 'batal'): ?>
            <a class="btn btn-accent btn-sm" href="<?= e(BASE_URL) ?>bill.php?id=<?= (int)$o['id'] ?>">Kelola Bill</a>
            <?php else: ?>
            <a class="btn btn-light btn-sm" href="<?= e(BASE_URL) ?>order-detail.php?id=<?= (int)$o['id'] ?>&print=1" target="_blank">Struk</a>
            <?php endif; ?>
            <?php if (in_array($o['status'], ['pending', 'proses'], true) && $o['lunas']): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="action" value="selesai">
                <button class="btn btn-primary btn-sm" type="submit">Selesai</button>
              </form>
            <?php endif; ?>
            <?php if (can_manage() && $o['status'] !== 'batal'): ?>
              <form method="post" style="display:inline" data-prompt="Alasan pembatalan transaksi <?= e($o['kode']) ?>:" data-prompt-field="alasan">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="action" value="batal">
                <input type="hidden" name="alasan" value="">
                <button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">Batal</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $qs(['page' => $page - 1]) ?>">&lsaquo;</a>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
      <?php if ($i === $page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="<?= $qs(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $qs(['page' => $page + 1]) ?>">&rsaquo;</a>
  </div>
  <?php endif; ?>
  <div class="card-body muted" style="font-size:12px;padding-top:12px">Total <?= angka($totalRows) ?> transaksi. Pesanan dine-in berstatus <em>Proses</em> sampai ditandai selesai (meja otomatis dikosongkan).</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
