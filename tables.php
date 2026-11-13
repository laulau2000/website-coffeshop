<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_outlet();

/* ---------------- Simpan (tambah/edit) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)post('action', '');
    $oid    = (int)post('id', 0);

    if ($action === 'simpan') {
        if (!can_manage()) { set_flash('danger', 'Akses ditolak.'); redirect('tables.php'); }
        $nama = mb_substr(trim((string)post('nama', '')), 0, 40);
        $area = mb_substr(trim((string)post('area', 'Indoor')), 0, 40) ?: 'Indoor';
        $kap  = min(100, max(1, (int)post('kapasitas', 4)));
        if (!$outletIdCheck = active_outlet_id()) { set_flash('warning', 'Pilih cabang aktif terlebih dahulu.'); redirect('tables.php'); }
        $status = in_array(post('status'), ['kosong','terisi','reservasi'], true) ? post('status') : 'kosong';
        $outletId = active_outlet_id();

        if ($nama === '') {
            set_flash('danger', 'Nama meja wajib diisi.');
        } elseif ($oid > 0) {
            q('UPDATE resto_tables SET nama=?, area=?, kapasitas=?, status=? WHERE id=? AND outlet_id=?',
              [$nama, $area, $kap, $status, $oid, $outletId]);
            set_flash('success', 'Meja berhasil diperbarui.');
        } else {
            q('INSERT INTO resto_tables (outlet_id, nama, area, kapasitas, status) VALUES (?,?,?,?,?)',
              [$outletId, $nama, $area, $kap, $status]);
            set_flash('success', 'Meja baru berhasil ditambahkan.');
        }
    }

    if ($action === 'status') {
        $newStatus = in_array(post('status'), ['kosong','terisi','reservasi'], true) ? post('status') : 'kosong';
        [$os, $osp] = outlet_scope('outlet_id');
        $aktif = (int)fetchCol("SELECT COUNT(*) FROM orders WHERE table_id = ? AND status IN ('pending','proses')", [$oid]);
        if ($newStatus === 'kosong' && $aktif > 0) {
            set_flash('warning', 'Meja masih memiliki ' . $aktif . ' pesanan berjalan. Tandai pesanan selesai di menu Transaksi.');
        } else {
            q('UPDATE resto_tables SET status=? WHERE id=?' . $os, array_merge([$newStatus, $oid], $osp));
            set_flash('success', 'Status meja diperbarui.');
        }
    }

    if ($action === 'hapus') {
        if (!can_manage()) { set_flash('danger', 'Akses ditolak.'); redirect('tables.php'); }
        [$os, $osp] = outlet_scope('outlet_id');
        q('DELETE FROM resto_tables WHERE id=?' . $os, array_merge([$oid], $osp));
        set_flash('success', 'Meja dihapus.');
    }
    redirect('tables.php');
}

[$ts, $tsp] = outlet_scope('outlet_id');
$meja = fetchAll('SELECT * FROM resto_tables WHERE 1=1' . $ts . ' ORDER BY area, nama', $tsp);

// Order berjalan per meja
$aktifPerMeja = [];
$rows = fetchAll(
    "SELECT table_id, COUNT(*) AS jml, COALESCE(SUM(total),0) AS nilai
     FROM orders WHERE status = 'proses' AND table_id IS NOT NULL" . $ts . " GROUP BY table_id",
    $tsp
);
foreach ($rows as $r) { $aktifPerMeja[(int)$r['table_id']] = $r; }

$page_title = 'Manajemen Meja';
$page_sub   = 'Denah & status meja';
$active     = 'tables.php';
include __DIR__ . '/includes/header.php';
?>

<div class="card mb-3">
  <div class="card-head">
    <h3>Denah Meja</h3>
    <span class="badge badge-success" style="margin-left:auto">Kosong <?= angka(count(array_filter($meja, fn($m) => $m['status'] === 'kosong'))) ?></span>
    <span class="badge badge-warning">Terisi <?= angka(count(array_filter($meja, fn($m) => $m['status'] === 'terisi'))) ?></span>
    <span class="badge badge-info">Reservasi <?= angka(count(array_filter($meja, fn($m) => $m['status'] === 'reservasi'))) ?></span>
    <?php if (can_manage()): ?>
      <button class="btn btn-primary btn-sm" style="margin-left:12px" data-modal-open="modal-meja" onclick="formMeja()">+ Tambah Meja</button>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$meja): ?>
      <div class="empty-state"><div class="es-ico">&#127869;</div>Belum ada meja. Tambahkan meja terlebih dahulu.</div>
    <?php else: ?>
      <div class="table-grid">
        <?php foreach ($meja as $m): ?>
          <div class="table-card <?= e($m['status']) ?>">
            <div class="t-name"><?= e($m['nama']) ?></div>
            <div class="t-meta"><?= e($m['area']) ?> &middot; <?= angka($m['kapasitas']) ?> org</div>
            <div style="margin:8px 0">
              <?php $sm = ['kosong'=>'badge-success','terisi'=>'badge-warning','reservasi'=>'badge-info']; ?>
              <span class="badge <?= $sm[$m['status']] ?>"><?= e(ucfirst($m['status'])) ?></span>
            </div>
            <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
              <a class="btn btn-primary btn-sm" href="<?= e(BASE_URL) ?>pos.php">Order</a>
              <?php if (can_manage()): ?>
                <button class="btn btn-light btn-sm" data-modal-open="modal-meja"
                        onclick='formMeja(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                <form method="post" style="display:inline" data-confirm="Hapus meja <?= e($m['nama']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="hapus">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">&times;</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can_manage()): ?>
<div class="modal-backdrop" id="modal-meja">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="simpan">
      <input type="hidden" name="id" id="m-id" value="0">
      <div class="modal-head">
        <h3 id="m-title">Tambah Meja</h3>
        <button class="modal-close" data-modal-close type="button">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="field-label">Nama / Nomor Meja</label>
          <input class="input" type="text" name="nama" id="m-nama" required placeholder="mis. Meja 07">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Area</label>
            <input class="input" type="text" name="area" id="m-area" value="Indoor" placeholder="Indoor / Outdoor / VIP">
          </div>
          <div class="form-group">
            <label class="field-label">Kapasitas (orang)</label>
            <input class="input mono" type="number" name="kapasitas" id="m-kap" min="1" value="4">
          </div>
        </div>
        <div class="form-group">
          <label class="field-label">Status</label>
          <select class="select" name="status" id="m-status">
            <option value="kosong">Kosong</option>
            <option value="terisi">Terisi</option>
            <option value="reservasi">Reservasi</option>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" data-modal-close type="button">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan</button>
      </div>
    </form>
  </div>
</div>
<script>
function formMeja(data) {
  data = data || {};
  document.getElementById('m-title').textContent = data.id ? 'Edit Meja' : 'Tambah Meja';
  document.getElementById('m-id').value    = data.id || 0;
  document.getElementById('m-nama').value  = data.nama || '';
  document.getElementById('m-area').value  = data.area || 'Indoor';
  document.getElementById('m-kap').value   = data.kapasitas || 4;
  document.getElementById('m-status').value = data.status || 'kosong';
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
