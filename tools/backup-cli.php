<?php
/**
 * Backup otomatis via cron, contoh (setiap hari jam 02:00):
 *   0 2 * * * php /path/kasirku/tools/backup-cli.php >> /path/kasirku/storage/logs/backup.log 2>&1
 * Windows (Task Scheduler): C:\xampp\php\php.exe C:\xampp\htdocs\kasirku\tools\backup-cli.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/backup.php';
$keep = (int)($argv[1] ?? 14);
$file = create_backup(db());
prune_backups(max(1, $keep));
echo date('c') . ' OK ' . basename($file) . ' (' . round(filesize($file) / 1024) . " KB)\n";
