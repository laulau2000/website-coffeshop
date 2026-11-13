<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_outlet();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!can_manage()) { set_flash('danger', 'Akses ditolak.'); redirect('categories.php'); }

    $action = (string)post('action', '');
    $id     = (int)post('id', 0);
    $outletId = active_outlet_id();

    if ($action === 'simpan') {
        $nama   = trim((string)post('nama', ''));
        $tipe   = in_array(post('tipe'), ['makanan','minuman','kopi','snack','dessert','lainnya'], true) ? post('tipe') : 'makanan';
        $urutan = (int)post('urutan', 0);
        $aktif  = post('aktif', '1') ? 1 : 0;

        if ($nama === '') {
            set_flash('danger', 'Nama kategori wajib diisi.');
        } elseif ($id > 0) {
            q('UPDATE categories SET nama=?, tipe=?, urutan=?, aktif=? WHERE id=?' . outlet_scope('outlet_id')[0],
              array_merge([$nama, $tipe, $urutan, $aktif, $id], outlet_scope('outlet_id')[1]));
            set_flash('success', 'Kategori diperbarui.');
        } else {
            q('INSERT INTO categories (outlet_id, nama, tipe, urutan, aktif) VALUES (?,?,?,?,?)',
              [$outletId, $nama, $tipe, $urutan, $aktif]);
            set_flash('success', 'Kategori ditambahkan.');
        }
    }

    if ($action === 'hapus') {
        [$os, $osp] = outlet_scope('outlet_id');
        $jml = (int)fetchCol('SELECT COUNT(*) FROM products WHERE category_id = ? AND deleted_at IS NULL' . $os, array_merge([$id], $osp));
        if ($jml > 0) {
            set_flash('warning', 'Kategori tidak dapat dihapus karena masih dipakai oleh ' . $jml . ' produk.');
        } else {
            q('DELETE FROM categories WHERE id=?' . $os, array_merge([$id], $osp));
            set_flash('success', 'Kategori dihapus.');
        }
    }
    redirect('categories.php');
}

[$cs, $csp] = outlet_scope('c.outlet_id');
$kategori = fetchAll(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.deleted_at IS NULL) AS jml_produk
     FROM categories c WHERE 1=1' . $cs . ' ORDER BY c.urutan, c.nama',
    $csp
);

$page_title = 'Kategori Menu';
$page_sub   = 'Kelompokkan menu berdasarkan jenis';
$active     = 'categories.php';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-head">
    <h3>Daftar Kategori</h3>
    <?php if (can_manage()): ?>
      <button class="btn btn-primary btn-sm" style="margin-left:auto" data-modal-open="modal-kat" onclick="formKat()">+ Tambah Kategori</button>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>#</th><th>Nama Kategori</th><th>Tipe</th><th class="text-center">Jumlah Menu</th><th class="text-center">Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$kategori): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:36px">Belum ada kategori.</td></tr>
      <?php else: foreach ($kategori as $k): ?>
        <tr>
          <td class="mono muted"><?= (int)$k['urutan'] ?></td>
          <td style="font-weight:600"><?= e($k['nama']) ?></td>
          <td><span class="badge badge-info"><?= e(ucfirst($k['tipe'])) ?></span></td>
          <td class="text-center mono"><?= angka($k['jml_produk']) ?></td>
          <td class="text-center"><span class="badge <?= $k['aktif'] ? 'badge-success' : 'badge-danger' ?>"><?= $k['aktif'] ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="text-right nowrap">
            <?php if (can_manage()): ?>
              <button class="btn btn-light btn-sm" data-modal-open="modal-kat"
                      onclick='formKat(<?= json_encode($k, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
              <form method="post" style="display:inline" data-confirm="Hapus kategori <?= e($k['nama']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="hapus">
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">&times;</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (can_manage()): ?>
<div class="modal-backdrop" id="modal-kat">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="simpan">
      <input type="hidden" name="id" id="k-id" value="0">
      <div class="modal-head">
        <h3 id="k-title">Tambah Kategori</h3>
        <button class="modal-close" data-modal-close type="button">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="field-label">Nama Kategori *</label>
          <input class="input" type="text" name="nama" id="k-nama" required placeholder="mis. Kopi Spesial">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Tipe</label>
            <select class="select" name="tipe" id="k-tipe">
              <?php foreach (['makanan','minuman','kopi','snack','dessert','lainnya'] as $t): ?>
                <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="field-label">Urutan Tampil</label>
            <input class="input mono" type="number" name="urutan" id="k-urutan" value="0" min="0">
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="aktif" id="k-aktif" value="1" checked> Kategori aktif
        </label>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" data-modal-close type="button">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan</button>
      </div>
    </form>
  </div>
</div>
<script>
function formKat(d) {
  d = d || {};
  document.getElementById('k-title').textContent = d.id ? 'Edit Kategori' : 'Tambah Kategori';
  document.getElementById('k-id').value      = d.id || 0;
  document.getElementById('k-nama').value    = d.nama || '';
  document.getElementById('k-tipe').value    = d.tipe || 'makanan';
  document.getElementById('k-urutan').value  = d.urutan || 0;
  document.getElementById('k-aktif').checked = d.id ? !!d.aktif : true;
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
