<?php
/** Sidebar navigasi utama */
$u = current_user();

$nav_icons = [
  'dashboard' => '<path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/>',
  'pos'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h8"/>',
  'order'     => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/>',
  'table'     => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18"/>',
  'menu'      => '<path d="M4 5h16M4 12h16M4 19h16"/>',
  'tag'       => '<path d="M20.6 13.4L13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2A2 2 0 0 1 3 12V4h8l9.6 9.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
  'report'    => '<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/>',
  'outlet'    => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
  'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2"/>',
  'log'       => '<path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/>',
  'bill'      => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
  'kitchen'   => '<path d="M6 13.9V20h12v-6.1"/><path d="M6 14a4 4 0 0 1-.6-7.9A5 5 0 0 1 15 4.5a4 4 0 0 1 3 9.5"/><path d="M6 17h12"/>',
  'shift'     => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
  'backup'    => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
  'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7.5 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 13.9H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7.5l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10.1 3V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1.3z"/>',
];

/** Cetak tautan nav */
function nav_link(string $href, string $label, string $icon, string $active, string $badge = ''): void
{
    global $nav_icons;
    $is = $active === $href ? ' active' : '';
    echo '<a class="nav-link' . $is . '" href="' . e(BASE_URL . $href) . '">';
    echo '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
       . ($nav_icons[$icon] ?? '') . '</svg>';
    echo '<span>' . e($label) . '</span>';
    if ($badge !== '') echo '<span class="nav-badge">' . e($badge) . '</span>';
    echo '</a>';
}

// Jumlah transaksi hari ini untuk badge
$trxHariIni = 0;
$billAktif = 0;
if ($u && $u['role'] !== 'dapur') {
    [$bs, $bp] = outlet_scope('o.outlet_id');
    $billAktif = (int)fetchCol("SELECT COUNT(*) FROM orders o WHERE o.lunas = 0 AND o.status <> 'batal'" . $bs, $bp);
    [$scope, $params] = outlet_scope('o.outlet_id');
    $trxHariIni = (int)fetchCol("SELECT COUNT(*) FROM orders o WHERE o.status <> 'batal' AND o.created_at >= CURDATE() AND o.created_at < CURDATE() + INTERVAL 1 DAY" . $scope, $params);
}
?>
<aside class="sidebar">
  <div class="sidebar-header">
    <div class="brand-logo"><?= e(brand_initials()) ?></div>
    <div class="brand-text">
      <strong><?= e(APP_NAME) ?></strong>
      <span><?= e(APP_TAGLINE) ?></span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php if ($u['role'] === 'dapur'): ?>
    <div class="nav-section">Dapur</div>
    <?php nav_link('kitchen.php', 'Layar Dapur / Bar', 'kitchen', $active); ?>
    <?php else: ?>
    <div class="nav-section">Operasional</div>
    <?php
      nav_link('index.php', 'Dashboard', 'dashboard', $active);
      nav_link('pos.php', 'Kasir / POS', 'pos', $active);
      nav_link('bills.php', 'Open Bill', 'bill', $active, $billAktif > 0 ? (string)$billAktif : '');
      nav_link('orders.php', 'Transaksi', 'order', $active, $trxHariIni > 0 ? (string)$trxHariIni : '');
      nav_link('kitchen.php', 'Layar Dapur / Bar', 'kitchen', $active);
      nav_link('shift.php', 'Shift Kasir', 'shift', $active);
      nav_link('tables.php', 'Meja', 'table', $active);
    ?>

    <div class="nav-section">Master Data</div>
    <?php
      nav_link('products.php', 'Menu & Produk', 'menu', $active);
      nav_link('categories.php', 'Kategori', 'tag', $active);
    ?>

    <?php if (can_manage()): ?>
    <div class="nav-section">Analisa</div>
    <?php nav_link('reports.php', 'Laporan Penjualan', 'report', $active); ?>
    <?php endif; ?>

    <div class="nav-section">Pengaturan</div>
    <?php
      if ($u['role'] === 'superadmin') {
          nav_link('outlets.php', 'Cabang / Outlet', 'outlet', $active);
      }
      if (can_manage()) {
          nav_link('users.php', 'Pengguna', 'user', $active);
          if (active_outlet_id()) {
              nav_link('settings.php', 'Pengaturan Toko', 'settings', $active);
          }
          nav_link('activity.php', 'Log Aktivitas', 'log', $active);
      }
      if ($u['role'] === 'superadmin') {
          nav_link('backup.php', 'Backup Data', 'backup', $active);
      }
    ?>
    <?php endif; ?>
    <?php nav_link('profile.php', 'Profil & Password', 'user', $active); ?>
  </nav>

  <div class="sidebar-user">
    <div class="avatar"><?= e(strtoupper(substr($u['nama'] ?? 'U', 0, 1))) ?></div>
    <div style="flex:1;min-width:0">
      <div class="u-name"><?= e($u['nama']) ?></div>
      <div class="u-role"><?= e(ucfirst($u['role'])) ?><?= $u['outlet_kode'] ? ' &middot; ' . e($u['outlet_kode']) : '' ?></div>
    </div>
    <form method="post" action="<?= e(BASE_URL) ?>logout.php" style="margin:0">
      <?= csrf_field() ?>
      <button type="submit" class="link-btn" title="Keluar" aria-label="Keluar" style="color:#8fb3a6">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
      </button>
    </form>
  </div>
</aside>
