<?php
/**
 * Migrasi skema otomatis (idempoten).
 * Database lama (v1) otomatis di-upgrade ke versi terbaru saat aplikasi diakses.
 */

function schema_cache_file(): string
{
    return STORAGE_PATH . '/.schema_version';
}

function ensure_schema_up_to_date(PDO $pdo): void
{
    $cache = schema_cache_file();
    if (is_file($cache) && (int)@file_get_contents($cache) === SCHEMA_VERSION) {
        return;
    }
    try {
        $hasUsers = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if (!$hasUsers) {
        return; // belum diinstal
    }
    $lock = (int)$pdo->query("SELECT GET_LOCK('kasirku_migrate', 30)")->fetchColumn();
    try {
        run_migrations($pdo);
    } finally {
        if ($lock) $pdo->query("SELECT RELEASE_LOCK('kasirku_migrate')");
    }
    @file_put_contents($cache, (string)SCHEMA_VERSION);
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function db_index_exists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $index]);
    return (int)$st->fetchColumn() > 0;
}

function db_fk_delete_rule(PDO $pdo, string $table, string $fk): ?string
{
    $st = $pdo->prepare('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $st->execute([$table, $fk]);
    $r = $st->fetchColumn();
    return $r === false ? null : (string)$r;
}

function run_migrations(PDO $pdo): void
{
    // Tabel baru (CREATE TABLE IF NOT EXISTS dari schema.sql)
    $sql = file_get_contents(ROOT_PATH . '/database/schema.sql');
    foreach (split_sql($sql) as $stmt) {
        $pdo->exec($stmt);
    }

    $addColumns = [
        'users'       => ['pin' => "VARCHAR(255) DEFAULT NULL AFTER `password`",
                          'deleted_at' => "DATETIME DEFAULT NULL AFTER `last_login`"],
        'products'    => ['lacak_stok' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `harga_modal`",
                          'deleted_at' => "DATETIME DEFAULT NULL AFTER `aktif`"],
        'orders'      => ['diskon_oleh' => "INT DEFAULT NULL AFTER `diskon_nilai`",
                          'diskon_alasan' => "VARCHAR(150) DEFAULT NULL AFTER `diskon_oleh`",
                          'service_persen' => "DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `diskon_alasan`",
                          'service_nilai' => "DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `service_persen`",
                          'batal_oleh' => "INT DEFAULT NULL AFTER `catatan`",
                          'batal_alasan' => "VARCHAR(255) DEFAULT NULL AFTER `batal_oleh`",
                          'batal_at' => "DATETIME DEFAULT NULL AFTER `batal_alasan`",
                          'lunas' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `batal_at`",
                          'paid_at' => "DATETIME DEFAULT NULL AFTER `lunas`",
                          'paid_by' => "INT DEFAULT NULL AFTER `paid_at`",
                          'shift_id' => "INT DEFAULT NULL AFTER `paid_by`"],
        'order_items' => ['harga_modal' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `harga`",
                          'stasiun' => "ENUM('dapur','bar') NOT NULL DEFAULT 'dapur' AFTER `catatan`",
                          'status_dapur' => "ENUM('baru','dimasak','siap','diantar') NOT NULL DEFAULT 'diantar' AFTER `stasiun`",
                          'batch' => "SMALLINT NOT NULL DEFAULT 1 AFTER `status_dapur`",
                          'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `batch`"],
        'settings'    => ['max_diskon_kasir' => "DECIMAL(5,2) NOT NULL DEFAULT 10 AFTER `service_persen`",
                          'lebar_struk' => "SMALLINT NOT NULL DEFAULT 58 AFTER `max_diskon_kasir`",
                          'qris_image' => "VARCHAR(255) DEFAULT NULL AFTER `footer_struk`"],
    ];
    foreach ($addColumns as $table => $cols) {
        foreach ($cols as $col => $def) {
            if (!db_column_exists($pdo, $table, $col)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                if ($table === 'order_items' && $col === 'harga_modal') {
                    // Isi snapshot modal dari harga modal produk saat ini (data lama)
                    $pdo->exec('UPDATE order_items oi JOIN products p ON p.id = oi.product_id SET oi.harga_modal = p.harga_modal');
                }
            }
        }
    }
    $pdo->exec('ALTER TABLE `users` MODIFY `username` VARCHAR(80) NOT NULL');
    $pdo->exec("ALTER TABLE `users` MODIFY `role` ENUM('superadmin','admin','kasir','dapur') NOT NULL DEFAULT 'kasir'");
    $pdo->exec("ALTER TABLE `order_items` MODIFY `status_dapur` ENUM('baru','dimasak','siap','diantar') NOT NULL DEFAULT 'baru'");
    $pdo->exec('ALTER TABLE `orders` MODIFY `kode` VARCHAR(40) NOT NULL');

    $indexes = [
        ['orders', 'idx_ord_outlet_created', '(`outlet_id`, `created_at`)'],
        ['orders', 'idx_ord_status_created', '(`status`, `created_at`)'],
        ['products', 'idx_prod_outlet_aktif', '(`outlet_id`, `aktif`)'],
        ['orders', 'idx_ord_shift', '(`shift_id`)'],
        ['orders', 'idx_ord_lunas', '(`outlet_id`, `lunas`)'],
        ['order_items', 'idx_item_dapur', '(`status_dapur`)'],
    ];
    foreach ($indexes as [$t, $name, $cols]) {
        if (!db_index_exists($pdo, $t, $name)) {
            $pdo->exec("ALTER TABLE `$t` ADD KEY `$name` $cols");
        }
    }

    // Data transaksi tidak boleh ikut terhapus saat cabang dihapus
    if (db_fk_delete_rule($pdo, 'orders', 'fk_ord_outlet') === 'CASCADE') {
        $pdo->exec('ALTER TABLE `orders` DROP FOREIGN KEY `fk_ord_outlet`');
        $pdo->exec('ALTER TABLE `orders` ADD CONSTRAINT `fk_ord_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE RESTRICT');
    }

    $st = $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute(['schema_version', (string)SCHEMA_VERSION]);
}

/** Pecah file SQL menjadi statement (abaikan komentar baris). */
function split_sql(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}
