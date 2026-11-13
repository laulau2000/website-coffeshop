<?php
/**
 * Installer KasirKu POS (sekali pakai).
 * - Hanya berjalan bila aplikasi BELUM terinstal (storage/installed.lock tidak ada
 *   DAN database belum berisi tabel users).
 * - Tidak pernah menghapus tabel/data yang sudah ada.
 * - Password superadmin ditentukan sendiri saat instalasi.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrate.php';

function installer_db_exists_with_data(): bool
{
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$locked = is_file(INSTALL_LOCK) || installer_db_exists_with_data();
$done   = false;
$errors = [];
$old    = ['nama' => 'Pemilik', 'username' => 'owner', 'outlet' => 'Cabang Pusat', 'demo' => '1'];

if ($locked) {
    http_response_code(403);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = [
        'nama'     => trim($_POST['nama'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'outlet'   => trim($_POST['outlet'] ?? ''),
        'demo'     => !empty($_POST['demo']) ? '1' : '',
    ];
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($old['nama'] === '' || $old['outlet'] === '') $errors[] = 'Nama pemilik dan nama cabang wajib diisi.';
    if (!preg_match('/^[a-zA-Z0-9_.]{3,40}$/', $old['username'])) $errors[] = 'Username 3-40 karakter (huruf, angka, titik, garis bawah).';
    if ($m = password_policy_error($pass)) $errors[] = $m;
    if ($pass !== $pass2) $errors[] = 'Konfirmasi password tidak sama.';

    if (!$errors) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . str_replace('`', '', DB_NAME) . '`');

            foreach (split_sql(file_get_contents(ROOT_PATH . '/database/schema.sql')) as $stmt) {
                $pdo->exec($stmt);
            }
            if ($old['demo']) {
                foreach (split_sql(file_get_contents(ROOT_PATH . '/database/demo.sql')) as $stmt) {
                    $pdo->exec($stmt);
                }
                $pdo->prepare('UPDATE outlets SET nama = ? WHERE id = 1')->execute([$old['outlet']]);
            } else {
                $pdo->prepare("INSERT INTO outlets (kode, nama, jenis) VALUES ('PST', ?, 'hybrid')")->execute([$old['outlet']]);
                $oid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO settings (outlet_id, nama_resto) VALUES (?, ?)')->execute([$oid, $old['outlet']]);
            }
            $pdo->prepare("INSERT INTO users (username, nama, password, role, outlet_id, aktif) VALUES (?, ?, ?, 'superadmin', NULL, 1)")
                ->execute([$old['username'], $old['nama'], password_hash($pass, PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
                ->execute(['schema_version', (string)SCHEMA_VERSION]);

            if (!is_dir(STORAGE_PATH)) @mkdir(STORAGE_PATH, 0775, true);
            if (!is_dir(UPLOAD_PATH)) @mkdir(UPLOAD_PATH, 0775, true);
            @file_put_contents(INSTALL_LOCK, 'installed ' . date('c') . "\n");
            @file_put_contents(schema_cache_file(), (string)SCHEMA_VERSION);
            $done = true;
        } catch (Throwable $ex) {
            app_log('Install gagal: ' . $ex->getMessage());
            $errors[] = 'Instalasi gagal: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Instalasi &middot; <?= e(APP_NAME) ?></title>
<?= favicon_tag() ?>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/app.css">
</head>
<body>
<div class="login-page">
  <div class="login-card" style="max-width:560px">
    <div class="login-brand">
      <div class="brand-logo"><?= e(brand_initials()) ?></div>
      <div class="text-center">
        <h2 style="margin:0">Instalasi <?= e(APP_NAME) ?></h2>
        <div class="muted" style="font-size:12.5px">Setup database &amp; akun pemilik</div>
      </div>
    </div>

    <?php if ($locked && !$done): ?>
      <div class="alert alert-danger">Aplikasi sudah terinstal. Installer dikunci untuk melindungi data Anda.</div>
      <p class="hint">Untuk instal ulang di server baru, gunakan database kosong dan hapus file <code>storage/installed.lock</code>.</p>
      <a class="btn btn-primary btn-block btn-lg" href="<?= e(BASE_URL) ?>login.php">Ke Halaman Masuk</a>
    <?php elseif ($done): ?>
      <div class="alert alert-success">Instalasi berhasil. Installer kini terkunci otomatis.</div>
      <p style="font-size:13.5px">Masuk dengan username <strong class="mono"><?= e($old['username']) ?></strong> dan password yang baru Anda buat.
        Tambahkan admin &amp; kasir melalui menu <em>Pengguna</em>.</p>
      <a class="btn btn-primary btn-block btn-lg" href="<?= e(BASE_URL) ?>login.php">Masuk ke Aplikasi</a>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div>
      <?php endif; ?>
      <div class="card card-pad" style="background:var(--surface-2);margin-bottom:16px">
        <table class="data" style="font-size:13px">
          <tr><td>Server DB</td><td class="mono"><?= e(DB_HOST . ':' . DB_PORT) ?></td></tr>
          <tr><td>Database</td><td class="mono"><?= e(DB_NAME) ?></td></tr>
        </table>
        <div class="hint mt-2">Ubah koneksi di <code>config/config.local.php</code> (salin dari <code>config.local.example.php</code>).</div>
      </div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-group"><label>Nama usaha / cabang pertama</label>
          <input class="input" name="outlet" value="<?= e($old['outlet']) ?>" required maxlength="120"></div>
        <div class="form-row">
          <div class="form-group"><label>Nama pemilik</label>
            <input class="input" name="nama" value="<?= e($old['nama']) ?>" required maxlength="100"></div>
          <div class="form-group"><label>Username</label>
            <input class="input" name="username" value="<?= e($old['username']) ?>" required pattern="[a-zA-Z0-9_.]{3,40}"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Password</label>
            <input class="input" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
          <div class="form-group"><label>Ulangi password</label>
            <input class="input" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
        </div>
        <div class="hint" style="margin:-6px 0 12px">Minimal 8 karakter, kombinasi huruf dan angka.</div>
        <label class="check"><input type="checkbox" name="demo" value="1" <?= $old['demo'] ? 'checked' : '' ?>> Sertakan data contoh (menu, meja, cabang Bandung)</label>
        <button class="btn btn-primary btn-block btn-lg mt-2" type="submit">Mulai Instalasi</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
