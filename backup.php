<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/backup.php';
require_role('superadmin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)post('action', '');
    if ($action === 'buat') {
        $file = create_backup(db());
        prune_backups(30);
        log_activity('backup', 'Buat backup ' . basename($file), null);
        set_flash('success', 'Backup dibuat: ' . basename($file));
    } elseif ($action === 'unduh' || $action === 'hapus') {
        $name = basename((string)post('file', ''));
        $path = backup_dir() . '/' . $name;
        if (!preg_match('/^kasirku_[\w.-]+\.sql\.gz$/', $name) || !is_file($path)) {
            set_flash('danger', 'File backup tidak ditemukan.');
        } elseif ($action === 'unduh') {
            log_activity('backup_unduh', 'Unduh backup ' . $name, null);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } else {
            @unlink($path);
            log_activity('backup_hapus', 'Hapus backup ' . $name, null);
            set_flash('success', 'Backup dihapus.');
        }
    }
    redirect('backup.php');
}
$files = list_backups();
$page_title = 'Backup Data';
$page_sub   = 'Cadangkan seluruh database (semua cabang)';
$active     = 'backup.php';
include __DIR__ . '/includes/header.php';
?>
<div class="grid" style="grid-template-columns: 1.4fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head"><h3>File Backup</h3>
      <form method="post" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="action" value="buat">
        <button class="btn btn-primary btn-sm" type="submit">Buat Backup Sekarang</button></form></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>File</th><th>Waktu</th><th class="text-right">Ukuran</th><th></th></tr></thead>
        <tbody>
        <?php if (!$files): ?><tr><td colspan="4" class="text-center muted" style="padding:24px">Belum ada backup.</td></tr><?php endif; ?>
        <?php foreach ($files as $f): ?>
          <tr><td class="mono" style="font-size:12px"><?= e($f['name']) ?></td><td class="muted nowrap"><?= e(tanggal_indo(date('Y-m-d H:i:s', $f['time']))) ?></td>
            <td class="text-right mono"><?= angka($f['size'] / 1024) ?> KB</td>
            <td class="text-right nowrap">
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="unduh"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><button class="btn btn-light btn-sm" type="submit">Unduh</button></form>
              <form method="post" style="display:inline" data-confirm="Hapus file backup ini?"><?= csrf_field() ?><input type="hidden" name="action" value="hapus"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">Hapus</button></form>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Backup Otomatis</h3></div>
    <div class="card-body" style="font-size:13.5px">
      <p style="margin-top:0">Jadwalkan backup harian agar data aman walau server/komputer rusak. Simpan salinan di luar server (Google Drive, flashdisk).</p>
      <p><strong>Linux (cron):</strong></p>
      <pre class="mono" style="white-space:pre-wrap;background:var(--surface-2);padding:10px;border-radius:8px;font-size:12px">0 2 * * * php <?= e(ROOT_PATH) ?>/tools/backup-cli.php</pre>
      <p><strong>Windows/XAMPP (Task Scheduler):</strong></p>
      <pre class="mono" style="white-space:pre-wrap;background:var(--surface-2);padding:10px;border-radius:8px;font-size:12px">C:\xampp\php\php.exe "<?= e(str_replace('/', '\\', ROOT_PATH)) ?>\tools\backup-cli.php"</pre>
      <p class="hint">Restore: impor file .sql.gz melalui phpMyAdmin (tab Import) ke database kosong.</p>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
