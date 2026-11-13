<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)post('action', '');
    $lama = (string)post('password_lama', '');
    if (!password_verify($lama, $me['password'])) {
        set_flash('danger', 'Password saat ini salah.');
        redirect('profile.php');
    }
    if ($action === 'password') {
        $baru = (string)post('password_baru', '');
        if ($err = password_policy_error($baru)) {
            set_flash('danger', $err);
        } elseif ($baru !== (string)post('password_ulang', '')) {
            set_flash('danger', 'Konfirmasi password tidak sama.');
        } elseif (password_verify($baru, $me['password'])) {
            set_flash('danger', 'Password baru harus berbeda dari password lama.');
        } else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), $me['id']]);
            refresh_password_signature((int)$me['id']);
            session_regenerate_id(true);
            log_activity('ganti_password', 'Ganti password sendiri');
            set_flash('success', 'Password berhasil diganti.');
        }
    } elseif ($action === 'pin' && $me['role'] !== 'kasir') {
        $pin = trim((string)post('pin', ''));
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            set_flash('danger', 'PIN harus 4-8 digit angka.');
        } else {
            q('UPDATE users SET pin = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $me['id']]);
            log_activity('ganti_pin', 'Set PIN supervisor');
            set_flash('success', 'PIN supervisor disimpan.');
        }
    }
    redirect('profile.php');
}

$page_title = 'Profil & Password';
$page_sub   = $me['nama'] . ' (' . $me['username'] . ')';
$active     = 'profile.php';
include __DIR__ . '/includes/header.php';
?>
<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h3>Ganti Password</h3></div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="form-group"><label class="field-label">Password saat ini</label>
          <input class="input" type="password" name="password_lama" required autocomplete="current-password"></div>
        <div class="form-group"><label class="field-label">Password baru</label>
          <input class="input" type="password" name="password_baru" required minlength="8" autocomplete="new-password">
          <div class="hint">Minimal 8 karakter, kombinasi huruf dan angka.</div></div>
        <div class="form-group"><label class="field-label">Ulangi password baru</label>
          <input class="input" type="password" name="password_ulang" required minlength="8" autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit">Simpan Password</button>
      </form>
    </div>
  </div>
  <?php if ($me['role'] !== 'kasir'): ?>
  <div class="card">
    <div class="card-head"><h3>PIN Supervisor</h3></div>
    <div class="card-body">
      <p class="hint" style="margin-top:0">PIN dipakai untuk menyetujui diskon kasir di atas batas. Status: <strong><?= $me['pin'] ? 'sudah diatur' : 'belum diatur' ?></strong>.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="pin">
        <div class="form-group"><label class="field-label">Password saat ini</label>
          <input class="input" type="password" name="password_lama" required></div>
        <div class="form-group"><label class="field-label">PIN baru (4-8 digit)</label>
          <input class="input mono" type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" maxlength="8" required></div>
        <button class="btn btn-primary" type="submit">Simpan PIN</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
