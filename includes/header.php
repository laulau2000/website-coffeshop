<?php
/**
 * Layout bagian atas: <head>, sidebar, topbar
 * Variabel yang bisa diisi sebelum include:
 *   $page_title, $page_sub, $active
 */
require_once __DIR__ . '/auth.php';
require_login();

$user       = current_user();
$page_title = $page_title ?? 'Dashboard';
$page_sub   = $page_sub   ?? '';
$active     = $active     ?? '';
$flashes    = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<?= favicon_tag() ?>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-overlay"></div>

  <div class="main">
    <header class="topbar">
      <button class="burger" id="burger" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <div>
        <div class="page-title"><?= e($page_title) ?></div>
        <?php if ($page_sub): ?><div class="page-sub"><?= e($page_sub) ?></div><?php endif; ?>
      </div>
      <div class="topbar-spacer"></div>

      <?php if ($user['role'] === 'superadmin'): ?>
        <form method="post" action="<?= e(BASE_URL) ?>switch-outlet.php" class="no-print" style="display:flex;gap:8px;align-items:center">
          <?= csrf_field() ?>
          <select name="outlet_id" class="select" style="width:auto;min-width:200px" onchange="this.form.submit()">
            <option value="0"<?= active_outlet_id() === null ? ' selected' : '' ?>>Semua Cabang</option>
            <?php foreach (fetchAll('SELECT id, nama FROM outlets WHERE aktif = 1 ORDER BY nama') as $o): ?>
              <option value="<?= (int)$o['id'] ?>"<?= active_outlet_id() === (int)$o['id'] ? ' selected' : '' ?>>
                <?= e($o['nama']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php else: ?>
        <span class="outlet-chip no-print"><span class="dot"></span><?= e($user['outlet_nama'] ?: 'Tanpa Cabang') ?></span>
      <?php endif; ?>

      <span class="badge badge-info no-print"><?= e(date('d M Y')) ?></span>
    </header>

    <main class="content">
<?php if (!empty($_SESSION['weak_password']) && ($active ?? '') !== 'profile.php'): ?>
  <div class="alert alert-warning no-print">Password Anda masih lemah/bawaan. <a href="<?= e(BASE_URL) ?>profile.php"><strong>Ganti password sekarang</strong></a> untuk mengamankan akun.</div>
<?php endif; ?>
<?php if ($flashes): ?>
<script type="application/json" id="flash-data"><?= json_encode($flashes, JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
