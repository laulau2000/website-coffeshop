-- =====================================================================
--  KasirKu POS - Skema Database v2
--  MySQL 5.7+ / MariaDB 10.3+
--  Dijalankan oleh install.php pada database KOSONG.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `app_meta` (
  `k` VARCHAR(50) PRIMARY KEY,
  `v` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `outlets` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `kode`       VARCHAR(20)  NOT NULL UNIQUE,
  `nama`       VARCHAR(120) NOT NULL,
  `jenis`      ENUM('restoran','coffee_shop','hybrid') NOT NULL DEFAULT 'hybrid',
  `alamat`     VARCHAR(255) DEFAULT NULL,
  `telepon`    VARCHAR(30)  DEFAULT NULL,
  `aktif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id`  INT DEFAULT NULL,
  `nama`       VARCHAR(100) NOT NULL,
  `username`   VARCHAR(80)  NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `pin`        VARCHAR(255) DEFAULT NULL,
  `role`       ENUM('superadmin','admin','kasir','dapur') NOT NULL DEFAULT 'kasir',
  `telepon`    VARCHAR(30)  DEFAULT NULL,
  `aktif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login` DATETIME     DEFAULT NULL,
  `deleted_at` DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_users_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `categories` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id` INT NOT NULL,
  `nama`      VARCHAR(80) NOT NULL,
  `tipe`      ENUM('makanan','minuman','kopi','snack','dessert','lainnya') NOT NULL DEFAULT 'makanan',
  `urutan`    INT NOT NULL DEFAULT 0,
  `aktif`     TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT `fk_cat_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id`   INT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `nama`        VARCHAR(120) NOT NULL,
  `sku`         VARCHAR(40)  DEFAULT NULL,
  `harga`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `harga_modal` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `lacak_stok`  TINYINT(1) NOT NULL DEFAULT 1,
  `stok`        INT NOT NULL DEFAULT 0,
  `satuan`      VARCHAR(20) NOT NULL DEFAULT 'pcs',
  `deskripsi`   TEXT,
  `gambar`      VARCHAR(255) DEFAULT NULL,
  `varian`      TEXT,
  `favorit`     TINYINT(1) NOT NULL DEFAULT 0,
  `aktif`       TINYINT(1) NOT NULL DEFAULT 1,
  `deleted_at`  DATETIME DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_prod_outlet_aktif` (`outlet_id`, `aktif`),
  CONSTRAINT `fk_prod_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prod_cat`    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `resto_tables` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id` INT NOT NULL,
  `nama`      VARCHAR(40) NOT NULL,
  `area`      VARCHAR(40) NOT NULL DEFAULT 'Indoor',
  `kapasitas` INT NOT NULL DEFAULT 4,
  `status`    ENUM('kosong','terisi','reservasi') NOT NULL DEFAULT 'kosong',
  CONSTRAINT `fk_tbl_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id`      INT NOT NULL,
  `user_id`        INT DEFAULT NULL,
  `table_id`       INT DEFAULT NULL,
  `kode`           VARCHAR(40) NOT NULL UNIQUE,
  `tipe`           ENUM('dine_in','take_away','delivery') NOT NULL DEFAULT 'dine_in',
  `status`         ENUM('pending','proses','selesai','batal') NOT NULL DEFAULT 'pending',
  `subtotal`       DECIMAL(14,2) NOT NULL DEFAULT 0,
  `diskon_persen`  DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `diskon_nilai`   DECIMAL(14,2) NOT NULL DEFAULT 0,
  `diskon_oleh`    INT DEFAULT NULL,
  `diskon_alasan`  VARCHAR(150) DEFAULT NULL,
  `service_persen` DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `service_nilai`  DECIMAL(14,2) NOT NULL DEFAULT 0,
  `pajak_persen`   DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `pajak_nilai`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total`          DECIMAL(14,2) NOT NULL DEFAULT 0,
  `bayar`          DECIMAL(14,2) NOT NULL DEFAULT 0,
  `kembalian`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `metode_bayar`   ENUM('cash','qris','debit','kredit','ewallet','transfer') NOT NULL DEFAULT 'cash',
  `nama_pelanggan` VARCHAR(100) DEFAULT NULL,
  `catatan`        VARCHAR(255) DEFAULT NULL,
  `batal_oleh`     INT DEFAULT NULL,
  `batal_alasan`   VARCHAR(255) DEFAULT NULL,
  `batal_at`       DATETIME DEFAULT NULL,
  `lunas`          TINYINT(1) NOT NULL DEFAULT 1,
  `paid_at`        DATETIME DEFAULT NULL,
  `paid_by`        INT DEFAULT NULL,
  `shift_id`       INT DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_ord_outlet_created` (`outlet_id`, `created_at`),
  KEY `idx_ord_status_created` (`status`, `created_at`),
  KEY `idx_ord_shift` (`shift_id`),
  KEY `idx_ord_lunas` (`outlet_id`, `lunas`),
  CONSTRAINT `fk_ord_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ord_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_ord_table`  FOREIGN KEY (`table_id`)  REFERENCES `resto_tables` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `order_id`    INT NOT NULL,
  `product_id`  INT DEFAULT NULL,
  `nama_produk` VARCHAR(120) NOT NULL,
  `varian`      VARCHAR(150) DEFAULT NULL,
  `harga`       DECIMAL(12,2) NOT NULL,
  `harga_modal` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `qty`         INT NOT NULL DEFAULT 1,
  `subtotal`    DECIMAL(14,2) NOT NULL,
  `catatan`     VARCHAR(150) DEFAULT NULL,
  `stasiun`     ENUM('dapur','bar') NOT NULL DEFAULT 'dapur',
  `status_dapur` ENUM('baru','dimasak','siap','diantar') NOT NULL DEFAULT 'baru',
  `batch`       SMALLINT NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_item_dapur` (`status_dapur`),
  CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_prod`  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_counters` (
  `outlet_id` INT NOT NULL,
  `tanggal`   DATE NOT NULL,
  `last_no`   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`outlet_id`, `tanggal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id`        INT NOT NULL UNIQUE,
  `nama_resto`       VARCHAR(120) DEFAULT NULL,
  `alamat`           VARCHAR(255) DEFAULT NULL,
  `telepon`          VARCHAR(30)  DEFAULT NULL,
  `logo`             VARCHAR(255) DEFAULT NULL,
  `pajak_persen`     DECIMAL(5,2) NOT NULL DEFAULT 0,
  `service_persen`   DECIMAL(5,2) NOT NULL DEFAULT 0,
  `max_diskon_kasir` DECIMAL(5,2) NOT NULL DEFAULT 10,
  `lebar_struk`      SMALLINT NOT NULL DEFAULT 58,
  `footer_struk`     VARCHAR(255) DEFAULT 'Terima kasih atas kunjungan Anda',
  `qris_image`       VARCHAR(255) DEFAULT NULL,
  `mata_uang`        VARCHAR(10) NOT NULL DEFAULT 'Rp',
  CONSTRAINT `fk_set_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `username`   VARCHAR(80) NOT NULL,
  `ip`         VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_login_user_time` (`username`, `created_at`),
  KEY `idx_login_ip_time` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id`  INT DEFAULT NULL,
  `user_id`    INT DEFAULT NULL,
  `aksi`       VARCHAR(40) NOT NULL,
  `deskripsi`  VARCHAR(500) NOT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_log_outlet_time` (`outlet_id`, `created_at`),
  KEY `idx_log_aksi` (`aksi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shifts` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `outlet_id`   INT NOT NULL,
  `user_id`     INT NOT NULL,
  `dibuka_at`   DATETIME NOT NULL,
  `kas_awal`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `ditutup_at`  DATETIME DEFAULT NULL,
  `ditutup_oleh` INT DEFAULT NULL,
  `kas_sistem`  DECIMAL(14,2) DEFAULT NULL,
  `kas_dihitung` DECIMAL(14,2) DEFAULT NULL,
  `selisih`     DECIMAL(14,2) DEFAULT NULL,
  `ringkasan`   TEXT,
  `catatan`     VARCHAR(255) DEFAULT NULL,
  KEY `idx_shift_open` (`outlet_id`, `user_id`, `ditutup_at`),
  CONSTRAINT `fk_shift_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shift_kas` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `shift_id`   INT NOT NULL,
  `user_id`    INT DEFAULT NULL,
  `tipe`       ENUM('masuk','keluar') NOT NULL,
  `jumlah`     DECIMAL(14,2) NOT NULL,
  `keterangan` VARCHAR(150) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_kas_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
