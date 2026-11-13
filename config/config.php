<?php
/**
 * Konfigurasi utama aplikasi KasirKu.
 *
 * JANGAN mengubah file ini untuk kredensial. Salin `config/config.local.example.php`
 * menjadi `config/config.local.php` lalu isi nilai sesuai server Anda.
 * File config.local.php tidak ikut ditimpa saat update aplikasi.
 */

$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require $__local;
}
unset($__local);

$__defaults = [
    // Database
    'DB_HOST'    => '127.0.0.1',
    'DB_PORT'    => '3306',
    'DB_NAME'    => 'kasir_resto',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_CHARSET' => 'utf8mb4',
    // Aplikasi (white-label: ubah di config.local.php)
    'APP_NAME'    => 'KasirKu',
    'APP_TAGLINE' => 'POS Restoran & Coffee Shop',
    'APP_TIMEZONE' => 'Asia/Jakarta',
    // Produksi: false. Aktifkan hanya saat development.
    'APP_DEBUG'  => false,
    // Sesi otomatis berakhir setelah tidak aktif (detik)
    'SESSION_IDLE_TIMEOUT' => 8 * 3600,
    // Batas percobaan login
    'LOGIN_MAX_ATTEMPTS'  => 5,
    'LOGIN_LOCK_MINUTES'  => 15,
];
foreach ($__defaults as $__k => $__v) {
    if (!defined($__k)) define($__k, $__v);
}
unset($__defaults, $__k, $__v);

define('APP_VERSION', '2.1.0');
define('SCHEMA_VERSION', 3);

date_default_timezone_set(APP_TIMEZONE);

// ---------- Path & URL ----------
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = preg_replace('#/(api|includes|tools)$#', '', $scriptDir);
    $base = rtrim($scriptDir, '/');
    define('BASE_URL', $base === '' ? '/' : $base . '/');
}

define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('UPLOAD_URL', BASE_URL . 'uploads/');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('INSTALL_LOCK', STORAGE_PATH . '/installed.lock');

// ---------- Error handling ----------
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ---------- Sesi ----------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('kasirku_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL,
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
