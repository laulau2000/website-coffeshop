<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin', 'admin');

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)post('action', '');
    $id     = (int)post('id', 0);

    if ($action === 'simpan') {
        $nama     = trim((string)post('nama', ''));
        $username = strtolower(trim((string)post('username', '')));
        $password = (string)post('password', '');
        $role     = in_array(post('role'), ['superadmin','admin','kasir','dapur'], true) ? post('role') : 'kasir';
        $aktif    = post('aktif', '1') ? 1 : 0;
        $telp     = mb_substr(trim((string)post('telepon', '')), 0, 30);
        $pin      = trim((string)post('pin', ''));
        $nama     = mb_substr($nama, 0, 100);

        // Admin hanya boleh mengelola cabangnya & tidak boleh membuat superadmin
        $outletId = $me['role'] === 'superadmin'
            ? (post('outlet_id') !== '' ? (int)post('outlet_id') : null)
            : (int)$me['outlet_id'];
        if ($me['role'] === 'admin' && $role === 'superadmin') {
            $role = 'kasir';
        }
        if ($role === 'superadmin') $outletId = null;
        if ($outletId && !fetchOne('SELECT id FROM outlets WHERE id = ?', [$outletId])) $outletId = null;
        $pwErr = ($password !== '' || $id === 0) ? password_policy_error($password) : null;

        if ($nama === '' || !preg_match('/^[a-z0-9_.]{3,40}$/', $username)) {
            set_flash('danger', 'Nama wajib diisi dan username 3-40 karakter (huruf kecil, angka, titik, garis bawah).');
        } elseif ($role !== 'superadmin' && !$outletId) {
            set_flash('danger', 'Admin dan kasir wajib ditempatkan di salah satu cabang.');
        } elseif ($pwErr) {
            set_flash('danger', $pwErr);
        } elseif ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) {
            set_flash('danger', 'PIN supervisor harus 4-8 digit angka.');
        } elseif ($id > 0 && $id === (int)$me['id'] && (!$aktif || $role !== $me['role'])) {
            set_flash('danger', 'Anda tidak dapat menonaktifkan atau mengubah peran akun sendiri.');
        } else {
            try {
                if ($id > 0) {
                    // Batasi: admin hanya bisa mengubah user di cabangnya
                    $cek = fetchOne('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
                    if (!$cek || ($me['role'] === 'admin' && (int)$cek['outlet_id'] !== (int)$me['outlet_id'])) {
                        set_flash('danger', 'Pengguna tidak ditemukan atau di luar wewenang Anda.');
                        redirect('users.php');
                    }
                    if ($me['role'] === 'admin' && $cek['role'] === 'superadmin') {
                        set_flash('danger', 'Anda tidak dapat mengubah akun superadmin.');
                        redirect('users.php');
                    }
                    if ($password !== '') {
                        q('UPDATE users SET nama=?, username=?, password=?, role=?, outlet_id=?, telepon=?, aktif=? WHERE id=?',
                          [$nama, $username, password_hash($password, PASSWORD_DEFAULT), $role, $outletId, $telp, $aktif, $id]);
                    } else {
                        q('UPDATE users SET nama=?, username=?, role=?, outlet_id=?, telepon=?, aktif=? WHERE id=?',
                          [$nama, $username, $role, $outletId, $telp, $aktif, $id]);
                    }
                    if ($pin !== '' && in_array($role, ['admin', 'superadmin'], true)) q('UPDATE users SET pin = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $id]);
                    if (in_array($role, ['kasir', 'dapur'], true) || post('hapus_pin')) q('UPDATE users SET pin = NULL WHERE id = ?', [$id]);
                    log_activity('user_ubah', 'Ubah pengguna ' . $username . ' (role ' . $role . ')' . ($password !== '' ? ', reset password' : '') . ($pin !== '' ? ', set PIN' : ''));
                    set_flash('success', 'Pengguna diperbarui.');
                } else {
                    q('INSERT INTO users (nama, username, password, pin, role, outlet_id, telepon, aktif) VALUES (?,?,?,?,?,?,?,?)',
                      [$nama, $username, password_hash($password, PASSWORD_DEFAULT),
                       ($pin !== '' && in_array($role, ['admin', 'superadmin'], true)) ? password_hash($pin, PASSWORD_DEFAULT) : null, $role, $outletId, $telp, $aktif]);
                    log_activity('user_tambah', 'Tambah pengguna ' . $username . ' (role ' . $role . ')');
                    set_flash('success', 'Pengguna baru berhasil dibuat.');
                }
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                set_flash('danger', 'Username "' . $username . '" sudah digunakan.');
            }
        }
    }

    if ($action === 'hapus') {
        if ($id === (int)$me['id']) {
            set_flash('danger', 'Anda tidak dapat menghapus akun sendiri.');
        } else {
            $cek = fetchOne('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
            if ($cek && !($me['role'] === 'admin' && ((int)$cek['outlet_id'] !== (int)$me['outlet_id'] || $cek['role'] === 'superadmin'))) {
                // Soft delete: nama kasir di riwayat transaksi tetap tercatat; username dibebaskan
                q('UPDATE users SET deleted_at = NOW(), aktif = 0, pin = NULL, username = CONCAT(username, "#del", id) WHERE id = ?', [$id]);
                log_activity('user_hapus', 'Hapus pengguna ' . $cek['username']);
                set_flash('success', 'Pengguna dihapus.');
            } else {
                set_flash('danger', 'Tidak diizinkan menghapus pengguna ini.');
            }
        }
    }
    redirect('users.php');
}

$roleCari = in_array(get('role', ''), ['superadmin', 'admin', 'kasir', 'dapur'], true) ? get('role') : '';
if ($me['role'] === 'superadmin') {
    $users = fetchAll(
        'SELECT u.*, o.nama AS outlet_nama FROM users u LEFT JOIN outlets o ON o.id = u.outlet_id
         WHERE u.deleted_at IS NULL' . ($roleCari !== '' ? ' AND u.role = ' . db()->quote($roleCari) : '') . '
         ORDER BY u.role, u.nama'
    );
    $outlets = fetchAll('SELECT id, nama FROM outlets WHERE aktif = 1 ORDER BY nama');
} else {
    $users = fetchAll(
        'SELECT u.*, o.nama AS outlet_nama FROM users u LEFT JOIN outlets o ON o.id = u.outlet_id
         WHERE u.outlet_id = ? AND u.role <> "superadmin" AND u.deleted_at IS NULL ORDER BY u.nama',
        [$me['outlet_id']]
    );
    $outlets = [];
}

$page_title = 'Pengguna';
$page_sub   = 'Kelola akun & hak akses';
$active     = 'users.php';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-head">
    <h3>Daftar Pengguna</h3>
    <form method="get" style="margin-left:auto;display:flex;gap:8px">
      <select class="select" name="role" onchange="this.form.submit()" style="width:auto">
        <option value="">Semua Role</option>
        <?php foreach (['superadmin','admin','kasir'] as $r): ?>
          <option value="<?= $r ?>"<?= $roleCari === $r ? ' selected' : '' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="button" data-modal-open="modal-user" onclick="formUser()">+ Tambah User</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Nama</th><th>Username</th><th>Role</th><th>Cabang</th><th>Kontak</th><th>Login Terakhir</th><th class="text-center">Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="8" class="text-center muted" style="padding:36px">Belum ada pengguna.</td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="avatar" style="width:30px;height:30px;flex:0 0 30px;font-size:12px"><?= e(strtoupper(substr($u['nama'], 0, 1))) ?></div>
              <strong><?= e($u['nama']) ?></strong>
            </div>
          </td>
          <td class="mono"><?= e($u['username']) ?></td>
          <td>
            <?php $rm = ['superadmin'=>'badge-accent','admin'=>'badge-info','kasir'=>'badge-success','dapur'=>'badge-warning']; ?>
            <span class="badge <?= $rm[$u['role']] ?? '' ?>"><?= e(ucfirst($u['role'])) ?></span>
          </td>
          <td class="muted"><?= e($u['outlet_nama'] ?: 'Semua Cabang') ?></td>
          <td class="muted mono"><?= e($u['telepon'] ?: '-') ?></td>
          <td class="muted"><?= $u['last_login'] ? waktu_lalu($u['last_login']) : 'belum pernah' ?></td>
          <td class="text-center"><span class="badge <?= $u['aktif'] ? 'badge-success' : 'badge-danger' ?>"><?= $u['aktif'] ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="text-right nowrap">
            <button class="btn btn-light btn-sm" data-modal-open="modal-user"
                    onclick='formUser(<?= json_encode(['id'=>(int)$u['id'],'nama'=>$u['nama'],'username'=>$u['username'],'role'=>$u['role'],'outlet_id'=>(int)$u['outlet_id'],'telepon'=>$u['telepon'],'aktif'=>(int)$u['aktif']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
              <form method="post" style="display:inline" data-confirm="Hapus pengguna <?= e($u['nama']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="hapus">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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

<div class="modal-backdrop" id="modal-user">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="simpan">
      <input type="hidden" name="id" id="u-id" value="0">
      <div class="modal-head">
        <h3 id="u-title">Tambah Pengguna</h3>
        <button class="modal-close" data-modal-close type="button">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Nama Lengkap *</label>
            <input class="input" type="text" name="nama" id="u-nama" required>
          </div>
          <div class="form-group">
            <label class="field-label">Username *</label>
            <input class="input" type="text" name="username" id="u-username" required placeholder="huruf kecil, tanpa spasi">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Password</label>
            <input class="input" type="password" name="password" id="u-pass" placeholder="kosongkan bila tidak diubah">
            <div class="hint">Min. 8 karakter, huruf &amp; angka. Wajib untuk pengguna baru.</div>
          </div>
          <div class="form-group">
            <label class="field-label">PIN Supervisor (admin/pemilik)</label>
            <input class="input mono" type="password" name="pin" id="u-pin" inputmode="numeric" maxlength="8" placeholder="4-8 digit, kosongkan bila tidak diubah" autocomplete="off">
            <div class="hint">Dipakai untuk menyetujui diskon besar dari kasir.</div>
          </div>
          <div class="form-group">
            <label class="field-label">No. Telepon</label>
            <input class="input" type="text" name="telepon" id="u-telp">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Role</label>
            <select class="select" name="role" id="u-role">
              <?php if ($me['role'] === 'superadmin'): ?>
                <option value="superadmin">Superadmin (semua cabang)</option>
              <?php endif; ?>
              <option value="admin">Admin Cabang</option>
              <option value="kasir">Kasir</option>
              <option value="dapur">Dapur / Bar (hanya layar dapur)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="field-label">Cabang</label>
            <?php if ($me['role'] === 'superadmin'): ?>
              <select class="select" name="outlet_id" id="u-outlet">
                <option value="">- Semua Cabang -</option>
                <?php foreach ($outlets as $o): ?>
                  <option value="<?= (int)$o['id'] ?>"><?= e($o['nama']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="input" type="text" value="<?= e($me['outlet_nama']) ?>" disabled>
            <?php endif; ?>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="aktif" id="u-aktif" value="1" checked> Akun aktif
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
function formUser(d) {
  d = d || {};
  document.getElementById('u-title').textContent = d.id ? 'Edit Pengguna' : 'Tambah Pengguna';
  document.getElementById('u-id').value       = d.id || 0;
  document.getElementById('u-nama').value     = d.nama || '';
  document.getElementById('u-username').value = d.username || '';
  document.getElementById('u-pass').value     = '';
  document.getElementById('u-pin').value      = '';
  document.getElementById('u-telp').value     = d.telepon || '';
  document.getElementById('u-role').value     = d.role || 'kasir';
  const outlet = document.getElementById('u-outlet');
  if (outlet) outlet.value = d.outlet_id || '';
  document.getElementById('u-aktif').checked = d.id ? !!d.aktif : true;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
