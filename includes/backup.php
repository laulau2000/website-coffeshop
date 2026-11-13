<?php
/**
 * Backup database ke file .sql.gz (tanpa perlu mysqldump).
 */
function backup_dir(): string
{
    $d = STORAGE_PATH . '/backups';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    if (!is_file($d . '/index.php')) @file_put_contents($d . '/index.php', "<?php http_response_code(404);\n");
    return $d;
}

function create_backup(PDO $pdo): string
{
    @set_time_limit(0);
    $file = backup_dir() . '/kasirku_' . DB_NAME . '_' . date('Ymd_His') . '.sql.gz';
    $gz = gzopen($file, 'wb6');
    if (!$gz) throw new RuntimeException('Tidak bisa menulis file backup. Periksa izin folder storage/.');
    gzwrite($gz, "-- Backup " . APP_NAME . " " . APP_VERSION . " | DB " . DB_NAME . " | " . date('c') . "\n");
    gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$t]) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $buf = [];
        while ($row = $st->fetch()) {
            $buf[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
            if (count($buf) >= 200) { gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n"); $buf = []; }
        }
        if ($buf) gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n");
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return $file;
}

function prune_backups(int $keep = 14): void
{
    $files = glob(backup_dir() . '/kasirku_*.sql.gz') ?: [];
    rsort($files);
    foreach (array_slice($files, $keep) as $f) @unlink($f);
}

function list_backups(): array
{
    $files = glob(backup_dir() . '/kasirku_*.sql.gz') ?: [];
    rsort($files);
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}
