<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)post('action', '');
    $id     = (int)post('id', 0);

    if ($action === 'simpan') {
        $kode  = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)post('kode', ''))));
        $kode  = substr($kode, 0, 10);
        $nama  = trim((string)post('nama', ''));
        $jenis = in_array(post('jenis'), ['restoran','coffee_shop','hybrid'], true) ? post('jenis') : 'hybrid';
        $alamat = trim((string)post('alamat', ''));
        $telp   = trim((string)post('telepon', ''));
        $aktif  = post('aktif', '1') ? 1 : 0;

        if ($kode === '' || $nama === '') {
            set_flash('danger', 'Kode (huruf/angka) dan nama cabang wajib diisi.');
        } elseif ($id > 0) {
            try {
                q('UPDATE outlets SET kode=?, nama=?, jenis=?, alamat=?, telepon=?, aktif=? WHERE id=?',
                  [$kode, $nama, $jenis, $alamat, $telp, $aktif, $id]);
                set_flash('success', 'Data cabang diperbarui.');
            } catch (PDOException $e) {
                set_flash('danger', 'Kode cabang sudah digunakan.');
            }
        } else {
            try {
                q('INSERT INTO outlets (kode, nama, jenis, alamat, telepon, aktif) VALUES (?,?,?,?,?,?)',
                  [$kode, $nama, $jenis, $alamat, $telp, $aktif]);
                $newId = (int)db()->lastInsertId();
                q('INSERT INTO settings (outlet_id, nama_resto, alamat, telepon, pajak_persen, service_persen)
                   VALUES (?,?,?,?,0,0)', [$newId, $nama, $alamat, $telp]);
                set_flash('success', 'Cabang baru berhasil ditambahkan.');
            } catch (PDOException $e) {
                set_flash('danger', 'Kode cabang sudah digunakan.');
            }
        }
    }

    if ($action === 'hapus') {
        $o = fetchOne('SELECT * FROM outlets WHERE id = ?', [$id]);
        $jmlOrder = $o ? (int)fetchCol('SELECT COUNT(*) FROM orders WHERE outlet_id = ?', [$id]) : 0;
        if (!$o) {
            set_flash('danger', 'Cabang tidak ditemukan.');
        } elseif ($jmlOrder > 0) {
            // Riwayat keuangan tidak boleh hilang: cabang hanya dinonaktifkan
            q('UPDATE outlets SET aktif = 0 WHERE id = ?', [$id]);
            q('UPDATE users SET aktif = 0 WHERE outlet_id = ? AND role <> "superadmin"', [$id]);
            if ((int)($_SESSION['outlet_aktif'] ?? 0) === $id) unset($_SESSION['outlet_aktif']);
            log_activity('cabang_nonaktif', 'Nonaktifkan cabang ' . $o['nama'] . ' (' . $jmlOrder . ' transaksi dipertahankan)', null);
            set_flash('warning', 'Cabang memiliki ' . $jmlOrder . ' transaksi, sehingga dinonaktifkan (bukan dihapus) agar laporan tetap utuh.');
        } else {
            q('UPDATE users SET outlet_id = NULL, aktif = 0 WHERE outlet_id = ? AND role <> "superadmin"', [$id]);
            q('DELETE FROM outlets WHERE id = ?', [$id]);
            if ((int)($_SESSION['outlet_aktif'] ?? 0) === $id) unset($_SESSION['outlet_aktif']);
            log_activity('cabang_hapus', 'Hapus cabang kosong ' . $o['nama'], null);
            set_flash('success', 'Cabang dihapus.');
        }
    }
    redirect('outlets.php');
}

$outlets = fetchAll(
    'SELECT o.*,
            (SELECT COUNT(*) FROM users u WHERE u.outlet_id = o.id AND u.deleted_at IS NULL) AS jml_user,
            (SELECT COUNT(*) FROM products p WHERE p.outlet_id = o.id AND p.deleted_at IS NULL) AS jml_produk,
            (SELECT COUNT(*) FROM orders od WHERE od.outlet_id = o.id) AS jml_order,
            (SELECT COALESCE(SUM(t.total),0) FROM orders t WHERE t.outlet_id = o.id AND t.status = "selesai") AS omzet
     FROM outlets o ORDER BY o.nama'
);

$page_title = 'Cabang / Outlet';
$page_sub   = 'Kelola seluruh cabang usaha';
$active     = 'outlets.php';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-head">
    <h3>Daftar Cabang</h3>
    <button class="btn btn-primary btn-sm" style="margin-left:auto" data-modal-open="modal-outlet" onclick="formOutlet()">+ Tambah Cabang</button>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Kode</th><th>Nama Cabang</th><th>Jenis</th><th>Kontak</th>
        <th class="text-center">User</th><th class="text-center">Menu</th><th class="text-center">Transaksi</th>
        <th class="text-right">Omzet</th><th class="text-center">Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($outlets as $o): ?>
        <tr>
          <td class="mono"><span class="badge badge-info"><?= e($o['kode']) ?></span></td>
          <td style="font-weight:600"><?= e($o['nama']) ?><div class="muted" style="font-size:11.5px;font-weight:400"><?= e($o['alamat'] ?: '-') ?></div></td>
          <td><?= e(ucfirst(str_replace('_', ' ', $o['jenis']))) ?></td>
          <td class="muted mono"><?= e($o['telepon'] ?: '-') ?></td>
          <td class="text-center mono"><?= angka($o['jml_user']) ?></td>
          <td class="text-center mono"><?= angka($o['jml_produk']) ?></td>
          <td class="text-center mono"><?= angka($o['jml_order']) ?></td>
          <td class="text-right mono"><?= rupiah($o['omzet']) ?></td>
          <td class="text-center"><span class="badge <?= $o['aktif'] ? 'badge-success' : 'badge-danger' ?>"><?= $o['aktif'] ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="text-right nowrap">
            <button class="btn btn-light btn-sm" data-modal-open="modal-outlet"
                    onclick='formOutlet(<?= json_encode($o, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="post" style="display:inline" data-confirm="Hapus cabang <?= e($o['nama']) ?>? Cabang yang sudah memiliki transaksi hanya akan dinonaktifkan.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">&times;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop" id="modal-outlet">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="simpan">
      <input type="hidden" name="id" id="o-id" value="0">
      <div class="modal-head">
        <h3 id="o-title">Tambah Cabang</h3>
        <button class="modal-close" data-modal-close type="button">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Kode Cabang *</label>
            <input class="input" type="text" name="kode" id="o-kode" required placeholder="mis. CBG2" maxlength="20">
          </div>
          <div class="form-group">
            <label class="field-label">Jenis Usaha</label>
            <select class="select" name="jenis" id="o-jenis">
              <option value="hybrid">Resto &amp; Coffee (Hybrid)</option>
              <option value="restoran">Restoran</option>
              <option value="coffee_shop">Coffee Shop</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="field-label">Nama Cabang *</label>
          <input class="input" type="text" name="nama" id="o-nama" required>
        </div>
        <div class="form-group">
          <label class="field-label">Alamat</label>
          <input class="input" type="text" name="alamat" id="o-alamat">
        </div>
        <div class="form-group">
          <label class="field-label">Telepon</label>
          <input class="input" type="text" name="telepon" id="o-telp">
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="aktif" id="o-aktif" value="1" checked> Cabang aktif
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
function formOutlet(d) {
  d = d || {};
  document.getElementById('o-title').textContent = d.id ? 'Edit Cabang' : 'Tambah Cabang';
  document.getElementById('o-id').value      = d.id || 0;
  document.getElementById('o-kode').value    = d.kode || '';
  document.getElementById('o-nama').value    = d.nama || '';
  document.getElementById('o-jenis').value   = d.jenis || 'hybrid';
  document.getElementById('o-alamat').value  = d.alamat || '';
  document.getElementById('o-telp').value    = d.telepon || '';
  document.getElementById('o-aktif').checked = d.id ? !!d.aktif : true;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
