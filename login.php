<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)post('username', ''));
    $password = (string)post('password', '');
    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif (($err = attempt_login($username, $password)) === null) {
        set_flash('success', 'Selamat datang kembali, ' . current_user()['nama'] . '!');
        redirect(['kasir' => 'pos.php', 'dapur' => 'kitchen.php'][current_user()['role']] ?? 'index.php');
    } else {
        $error = $err;
    }
}
$flashes = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk &middot; <?= e(APP_NAME) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<?= favicon_tag() ?>
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-brand">
      <div class="brand-logo"><?= e(brand_initials()) ?></div>
      <div class="text-center">
        <h2 style="margin:0"><?= e(APP_NAME) ?></h2>
        <div class="muted" style="font-size:12.5px"><?= e(APP_TAGLINE) ?></div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="field-label" for="username">Username</label>
        <input class="input" type="text" id="username" name="username" placeholder="Username" required autofocus autocomplete="username" value="<?= e($username ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="field-label" for="password">Password</label>
        <input class="input" type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Masuk</button>
    </form>

    <div class="hint text-center" style="margin-top:18px">Lupa password? Hubungi admin/pemilik usaha untuk reset.</div>
  </div>
</div>
<div id="toast-wrap"></div>
<script src="<?= e(BASE_URL) ?>assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
<?php if ($flashes): ?><script>document.addEventListener('DOMContentLoaded',()=><?= json_encode($flashes) ?>.forEach(f=>KasirKu.toast(f.message,f.type)));</script><?php endif; ?>
</body>
</html>
