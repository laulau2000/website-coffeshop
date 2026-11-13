<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin', 'admin', 'kasir');
require_outlet();

/* ---------------- Simpan produk ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action   = (string)post('action', '');
    $outletId = active_outlet_id();

    if (!can_manage()) { set_flash('danger', 'Akses ditolak.'); redirect('products.php'); }
    [$os, $osp] = outlet_scope('outlet_id');

    if ($action === 'hapus') {
        // Soft delete: riwayat transaksi tetap utuh
        $pr = fetchOne('SELECT id, nama FROM products WHERE id=? AND deleted_at IS NULL' . $os, array_merge([(int)post('id')], $osp));
        if ($pr) {
            q('UPDATE products SET deleted_at = NOW(), aktif = 0 WHERE id = ?', [$pr['id']]);
            log_activity('produk_hapus', 'Hapus produk "' . $pr['nama'] . '" (#' . $pr['id'] . ')');
            set_flash('success', 'Produk berhasil dihapus.');
        }
        redirect('products.php');
    }

    $id       = (int)post('id', 0);
    $nama     = mb_substr(trim((string)post('nama', '')), 0, 120);
    $catId    = post('category_id') ? (int)post('category_id') : null;
    $sku      = mb_substr(trim((string)post('sku', '')), 0, 40);
    $harga    = round((float)post('harga', 0), 2);
    $modal    = round((float)post('harga_modal', 0), 2);
    $lacak    = post('lacak_stok') ? 1 : 0;
    $stok     = (int)post('stok', 0);
    $satuan   = mb_substr(trim((string)post('satuan', 'pcs')), 0, 20) ?: 'pcs';
    $deskripsi = mb_substr(trim((string)post('deskripsi', '')), 0, 1000);
    $varian   = varian_to_json((string)post('varian', ''));
    $favorit  = post('favorit') ? 1 : 0;
    $aktif    = post('aktif') ? 1 : 0;

    $err = null;
    if ($nama === '') $err = 'Nama produk wajib diisi.';
    elseif ($harga < 0 || $modal < 0 || $harga > 100000000) $err = 'Harga tidak valid.';
    elseif ($stok < 0 || $stok > 1000000) $err = 'Stok tidak valid.';
    elseif ($catId && !fetchOne('SELECT id FROM categories WHERE id = ? AND outlet_id = ?', [$catId, $outletId])) $err = 'Kategori tidak valid.';
    if ($err) { set_flash('danger', $err); redirect('products.php'); }

    try {
        $gambarBaru = save_uploaded_image('gambar', 'menu');
    } catch (RuntimeException $ex) {
        set_flash('danger', $ex->getMessage());
        redirect('products.php');
    }

    if ($id > 0) {
        $old = fetchOne('SELECT * FROM products WHERE id=? AND deleted_at IS NULL' . $os, array_merge([$id], $osp));
        if (!$old) { delete_upload($gambarBaru); set_flash('danger', 'Produk tidak ditemukan.'); redirect('products.php'); }

        $gambar = $old['gambar'];
        if ($gambarBaru) { delete_upload($gambar); $gambar = $gambarBaru; }
        if (post('hapus_gambar')) { delete_upload($gambar); $gambar = null; }

        q('UPDATE products SET category_id=?, nama=?, sku=?, harga=?, harga_modal=?, lacak_stok=?, stok=?, satuan=?,
              deskripsi=?, varian=?, favorit=?, aktif=?, gambar=? WHERE id=?',
          [$catId, $nama, $sku, $harga, $modal, $lacak, $stok, $satuan, $deskripsi, $varian, $favorit, $aktif, $gambar, $id]);
        $chg = [];
        if ((float)$old['harga'] != $harga) $chg[] = 'harga ' . rupiah($old['harga']) . ' -> ' . rupiah($harga);
        if ((float)$old['harga_modal'] != $modal) $chg[] = 'modal ' . rupiah($old['harga_modal']) . ' -> ' . rupiah($modal);
        if ((int)$old['stok'] != $stok) $chg[] = 'stok ' . (int)$old['stok'] . ' -> ' . $stok;
        if ($chg) log_activity('produk_ubah', '"' . $nama . '": ' . implode(', ', $chg));
        set_flash('success', 'Produk "' . $nama . '" berhasil diperbarui.');
    } else {
        q('INSERT INTO products (outlet_id, category_id, nama, sku, harga, harga_modal, lacak_stok, stok, satuan, deskripsi, varian, favorit, aktif, gambar)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$outletId, $catId, $nama, $sku, $harga, $modal, $lacak, $stok, $satuan, $deskripsi, $varian, $favorit, $aktif, $gambarBaru]);
        log_activity('produk_tambah', 'Tambah produk "' . $nama . '" harga ' . rupiah($harga));
        set_flash('success', 'Produk "' . $nama . '" berhasil ditambahkan.');
    }
    redirect('products.php');
}

/* ---------------- Data ---------------- */
$katCari   = (int)get('kategori', 0);
$keyword   = trim((string)get('q', ''));

[$ps, $psp] = outlet_scope('p.outlet_id');
$w = ['p.deleted_at IS NULL'];
$p = [];
if ($katCari > 0) { $w[] = 'p.category_id = ?'; $p[] = $katCari; }
if ($keyword !== '') { $w[] = '(p.nama LIKE ? OR p.sku LIKE ?)'; $p[] = "%$keyword%"; $p[] = "%$keyword%"; }
$produk = fetchAll(
    'SELECT p.*, c.nama AS kategori FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE ' . implode(' AND ', $w) . $ps . ' ORDER BY c.urutan, p.nama',
    array_merge($p, $psp)
);

[$cs, $csp] = outlet_scope('outlet_id');
$kategori = fetchAll('SELECT * FROM categories WHERE 1=1' . $cs . ' ORDER BY urutan, nama', $csp);

$totalNilaiStok = array_sum(array_map(fn($x) => $x['lacak_stok'] ? $x['harga_modal'] * $x['stok'] : 0, $produk));

$page_title = 'Menu & Produk';
$page_sub   = 'Kelola daftar menu, harga, stok dan varian';
$active     = 'products.php';
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-3 mb-3">
  <div class="stat"><span class="stat-label">Total Menu</span><span class="stat-value"><?= angka(count($produk)) ?></span><span class="stat-foot"><?= angka(count(array_filter($produk, fn($x) => $x['aktif']))) ?> aktif</span></div>
  <?php if (can_manage()): ?>
  <div class="stat accent"><span class="stat-label">Nilai Stok (Modal)</span><span class="stat-value"><?= rupiah($totalNilaiStok) ?></span><span class="stat-foot">Berdasarkan harga modal</span></div>
  <?php else: ?>
  <div class="stat accent"><span class="stat-label">Stok Menipis</span><span class="stat-value"><?= angka(count(array_filter($produk, fn($x) => $x['lacak_stok'] && $x['stok'] <= 5))) ?></span><span class="stat-foot">Stok 5 atau kurang</span></div>
  <?php endif; ?>
  <div class="stat info"><span class="stat-label">Kategori</span><span class="stat-value"><?= angka(count($kategori)) ?></span><span class="stat-foot">Kelompok menu</span></div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div style="flex:1;min-width:200px">
        <label class="field-label">Cari Produk</label>
        <input class="input" type="text" name="q" value="<?= e($keyword) ?>" placeholder="nama atau SKU">
      </div>
      <div style="min-width:200px">
        <label class="field-label">Kategori</label>
        <select class="select" name="kategori">
          <option value="0">Semua Kategori</option>
          <?php foreach ($kategori as $k): ?>
            <option value="<?= (int)$k['id'] ?>"<?= $katCari === (int)$k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit">Filter</button>
      <a class="btn btn-light" href="<?= e(BASE_URL) ?>products.php">Reset</a>
      <?php if (can_manage()): ?>
        <button class="btn btn-accent" type="button" data-modal-open="modal-produk" onclick="formProduk()" style="margin-left:auto">+ Tambah Menu</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Menu</th><th>Kategori</th><th>SKU</th><th class="text-right">Harga</th><th class="text-center">Stok</th><th class="text-center">Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$produk): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:36px">Belum ada produk.</td></tr>
      <?php else: foreach ($produk as $pr): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:38px;height:38px;border-radius:10px;overflow:hidden;background:var(--surface-2);display:grid;place-items:center;flex:0 0 38px">
                <?php if ($pr['gambar'] && is_file(UPLOAD_PATH . '/' . $pr['gambar'])): ?>
                  <img src="<?= e(UPLOAD_URL . $pr['gambar']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
                <?php else: ?><span>&#9749;</span><?php endif; ?>
              </div>
              <div>
                <div style="font-weight:600"><?= e($pr['nama']) ?> <?= $pr['favorit'] ? '<span class="badge badge-accent">FAV</span>' : '' ?></div>
                <?php if ($pr['varian']): ?><div class="muted" style="font-size:11.5px">Varian: <?= e(str_replace("\n", ' | ', varian_to_text($pr['varian']))) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="muted"><?= e($pr['kategori'] ?: '-') ?></td>
          <td class="mono muted"><?= e($pr['sku'] ?: '-') ?></td>
          <td class="text-right mono"><?= rupiah($pr['harga']) ?></td>
          <td class="text-center">
            <?php if (!$pr['lacak_stok']): ?><span class="badge">Tanpa batas<?php else: ?>
            <span class="badge <?= $pr['stok'] <= 5 ? 'badge-danger' : ($pr['stok'] <= 10 ? 'badge-warning' : 'badge-success') ?>">
              <?= angka($pr['stok']) ?> <?= e($pr['satuan']) ?><?php endif; ?>
            </span>
          </td>
          <td class="text-center">
            <span class="badge <?= $pr['aktif'] ? 'badge-success' : 'badge-danger' ?>"><?= $pr['aktif'] ? 'Aktif' : 'Nonaktif' ?></span>
          </td>
          <td class="text-right nowrap">
            <?php if (can_manage()): ?>
              <button class="btn btn-light btn-sm" data-modal-open="modal-produk"
                      onclick='formProduk(<?= json_encode([
                        'id' => (int)$pr['id'], 'nama' => $pr['nama'], 'category_id' => (int)$pr['category_id'],
                        'sku' => $pr['sku'], 'harga' => (float)$pr['harga'], 'harga_modal' => can_manage() ? (float)$pr['harga_modal'] : 0, 'lacak_stok' => (int)$pr['lacak_stok'],
                        'stok' => (int)$pr['stok'], 'satuan' => $pr['satuan'], 'deskripsi' => $pr['deskripsi'],
                        'varian' => varian_to_text($pr['varian']), 'favorit' => (int)$pr['favorit'], 'aktif' => (int)$pr['aktif'],
                        'gambar' => $pr['gambar'] ? UPLOAD_URL . $pr['gambar'] : null,
                      ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>Edit</button>
              <form method="post" style="display:inline" data-confirm="Hapus menu <?= e($pr['nama']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="hapus">
                <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
                <button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">&times;</button>
              </form>
            <?php else: ?>
              <span class="muted" style="font-size:12px">-</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (can_manage()): ?>
<div class="modal-backdrop" id="modal-produk">
  <div class="modal modal-lg">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="simpan">
      <input type="hidden" name="id" id="p-id" value="0">
      <div class="modal-head">
        <h3 id="p-title">Tambah Menu</h3>
        <button class="modal-close" data-modal-close type="button">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Nama Menu *</label>
            <input class="input" type="text" name="nama" id="p-nama" required>
          </div>
          <div class="form-group">
            <label class="field-label">Kategori</label>
            <select class="select" name="category_id" id="p-cat">
              <option value="">- Tanpa Kategori -</option>
              <?php foreach ($kategori as $k): ?>
                <option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Harga Jual *</label>
            <input class="input mono" type="number" name="harga" id="p-harga" min="0" step="500" value="0" required>
          </div>
          <div class="form-group">
            <label class="field-label">Harga Modal</label>
            <input class="input mono" type="number" name="harga_modal" id="p-modal" min="0" step="500" value="0">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="field-label">SKU / Kode</label>
            <input class="input" type="text" name="sku" id="p-sku" placeholder="mis. KP-001">
          </div>
          <div class="form-group">
            <label class="field-label">Satuan</label>
            <input class="input" type="text" name="satuan" id="p-satuan" value="pcs" placeholder="pcs / cup / porsi">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Stok</label>
            <input class="input mono" type="number" name="stok" id="p-stok" min="0" value="0">
            <label class="check" style="margin-top:6px"><input type="checkbox" name="lacak_stok" id="p-lacak" value="1" checked> Lacak stok (matikan untuk menu racikan tanpa batas)</label>
          </div>
          <div class="form-group">
            <label class="field-label">Gambar Menu</label>
            <input class="input" type="file" name="gambar" id="p-gambar" accept="image/*">
            <div id="p-gambar-info" class="hint"></div>
            <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12px">
              <input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar saat ini
            </label>
          </div>
        </div>

        <div class="form-group">
          <label class="field-label">Deskripsi</label>
          <textarea class="textarea" name="deskripsi" id="p-desk" rows="2" placeholder="Deskripsi singkat menu"></textarea>
        </div>

        <div class="form-group">
          <label class="field-label">Varian (opsional)</label>
          <textarea class="textarea mono" name="varian" id="p-varian" rows="3"
            placeholder="Suhu: Hot, Ice(+3000)&#10;Size: Reguler, Large(+5000)"></textarea>
          <div class="hint">Satu baris per grup varian. Format: <code>Nama Grup: Opsi1, Opsi2(+tambahan harga)</code></div>
        </div>

        <div style="display:flex;gap:20px">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px">
            <input type="checkbox" name="favorit" id="p-fav" value="1"> Tandai Favorit
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px">
            <input type="checkbox" name="aktif" id="p-aktif" value="1" checked> Aktif dijual
          </label>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" data-modal-close type="button">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Menu</button>
      </div>
    </form>
  </div>
</div>

<script>
function formProduk(d) {
  d = d || {};
  document.getElementById('p-title').textContent = d.id ? 'Edit Menu' : 'Tambah Menu';
  document.getElementById('p-id').value      = d.id || 0;
  document.getElementById('p-nama').value    = d.nama || '';
  document.getElementById('p-cat').value     = d.category_id || '';
  document.getElementById('p-sku').value     = d.sku || '';
  document.getElementById('p-harga').value   = d.harga || 0;
  document.getElementById('p-modal').value   = d.harga_modal || 0;
  document.getElementById('p-stok').value    = d.stok != null ? d.stok : 0;
  document.getElementById('p-satuan').value  = d.satuan || 'pcs';
  document.getElementById('p-desk').value    = d.deskripsi || '';
  document.getElementById('p-varian').value  = d.varian || '';
  document.getElementById('p-fav').checked   = !!d.favorit;
  document.getElementById('p-lacak').checked = d.lacak_stok == null ? true : !!d.lacak_stok;
  document.getElementById('p-aktif').checked = d.id ? !!d.aktif : true;
  const info = document.getElementById('p-gambar-info');
  info.innerHTML = d.gambar ? 'Gambar saat ini: <img src="' + d.gambar + '" style="height:34px;vertical-align:middle;border-radius:6px">' : 'Belum ada gambar.';
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
