<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin', 'admin');

$aksi = preg_match('/^[a-z_]{1,40}$/', (string)get('aksi', '')) ? get('aksi') : '';
$page = max(1, (int)get('page', 1));
$per  = 50;
[$os, $osp] = outlet_scope('a.outlet_id');
if (is_role('superadmin') && !active_outlet_id()) { $os = ''; $osp = []; }
$w = "WHERE a.aksi <> 'checkout_ref'" . ($aksi ? ' AND a.aksi = ?' : '') . $os;
$p = array_merge($aksi ? [$aksi] : [], $osp);
$total = (int)fetchCol("SELECT COUNT(*) FROM activity_log a $w", $p);
$pages = max(1, (int)ceil($total / $per));
$rows = fetchAll("SELECT a.*, u.nama user_nama, o.nama outlet_nama FROM activity_log a
                  LEFT JOIN users u ON u.id = a.user_id LEFT JOIN outlets o ON o.id = a.outlet_id
                  $w ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$jenis = fetchAll("SELECT DISTINCT aksi FROM activity_log WHERE aksi <> 'checkout_ref' ORDER BY aksi");

$page_title = 'Log Aktivitas';
$page_sub   = 'Jejak audit: diskon, pembatalan, perubahan harga, login';
$active     = 'activity.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head">
    <h3>Aktivitas Terbaru</h3>
    <form method="get" style="margin-left:auto">
      <select class="select" name="aksi" onchange="this.form.submit()" style="width:auto">
        <option value="">Semua aksi</option>
        <?php foreach ($jenis as $j): ?><option value="<?= e($j['aksi']) ?>"<?= $aksi === $j['aksi'] ? ' selected' : '' ?>><?= e($j['aksi']) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Waktu</th><th>Pengguna</th><th>Cabang</th><th>Aksi</th><th>Keterangan</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center muted" style="padding:30px">Belum ada aktivitas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap muted"><?= e(tanggal_indo($r['created_at'])) ?></td>
          <td><?= e($r['user_nama'] ?: '-') ?></td>
          <td class="muted"><?= e($r['outlet_nama'] ?: '-') ?></td>
          <td><span class="badge"><?= e($r['aksi']) ?></span></td>
          <td><?= e($r['deskripsi']) ?></td>
          <td class="mono muted"><?= e($r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
      <?php if ($i === $page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="?<?= e(http_build_query(['aksi' => $aksi, 'page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
