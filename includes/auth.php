<?php
/**
 * Autentikasi & otorisasi berbasis peran (role)
 * Role: superadmin | admin | kasir
 */
require_once __DIR__ . '/functions.php';

function current_user(bool $refresh = false): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded && !$refresh) return $user;
    $loaded = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = fetchOne(
        'SELECT u.*, o.nama AS outlet_nama, o.kode AS outlet_kode
         FROM users u LEFT JOIN outlets o ON o.id = u.outlet_id
         WHERE u.id = ? AND u.aktif = 1 AND u.deleted_at IS NULL',
        [(int)$_SESSION['user_id']]
    );
    $idle = time() - (int)($_SESSION['last_activity'] ?? time());
    $stale = $user && (
        $idle > SESSION_IDLE_TIMEOUT
        || ($_SESSION['pwd_sig'] ?? '') !== substr(hash('sha256', $user['password']), 0, 16)
    );
    if (!$user || $stale) {
        $_SESSION = [];
        session_regenerate_id(true);
        if ($stale) $_SESSION['_flash'][] = ['type' => 'warning', 'message' => 'Sesi berakhir. Silakan masuk kembali.'];
        $user = null;
        return null;
    }
    $_SESSION['last_activity'] = time();
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (is_json_request()) {
            json_response(['ok' => false, 'message' => 'Sesi berakhir. Silakan masuk kembali.'], 401);
        }
        if (empty($_SESSION['_flash'])) set_flash('warning', 'Silakan masuk terlebih dahulu.');
        redirect('login.php');
    }
    // Akun dapur hanya boleh membuka layar dapur & profil
    if (user_role() === 'dapur' && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['kitchen.php', 'profile.php', 'logout.php'], true)) {
        if (is_json_request()) json_response(['ok' => false, 'message' => 'Akses ditolak.'], 403);
        redirect('kitchen.php');
    }
}

function is_json_request(): bool
{
    return str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function user_role(): string
{
    $u = current_user();
    return $u['role'] ?? 'guest';
}

function is_role(string ...$roles): bool
{
    return in_array(user_role(), $roles, true);
}

function require_role(string ...$roles): void
{
    require_login();
    if (!is_role(...$roles)) {
        if (is_json_request()) json_response(['ok' => false, 'message' => 'Akses ditolak.'], 403);
        set_flash('danger', 'Anda tidak memiliki akses ke halaman tersebut.');
        redirect('index.php');
    }
}

/** Cek apakah user boleh mengelola data master (menu, meja, laporan). */
function can_manage(): bool
{
    return is_role('superadmin', 'admin');
}

/**
 * Pastikan ada cabang aktif. Halaman yang mengubah data wajib memanggil ini,
 * karena superadmin bisa berada pada mode "Semua Cabang" (hanya untuk melihat).
 */
function require_outlet(): int
{
    $oid = active_outlet_id();
    if (!$oid) {
        set_flash('warning', 'Pilih cabang aktif terlebih dahulu pada menu di kanan atas.');
        redirect('index.php');
    }
    return $oid;
}

/** Admin hanya boleh mengakses data cabangnya sendiri. */
function user_can_access_outlet(int $outletId): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'superadmin') return true;
    return (int)$u['outlet_id'] === $outletId;
}

/* ------------------------------------------------------------------
 *  Kebijakan password & PIN supervisor
 * ------------------------------------------------------------------ */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8) return 'Password minimal 8 karakter.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password harus mengandung huruf dan angka.';
    }
    if (in_array(strtolower($password), ['admin123', 'password1', 'kasir123', '12345678a', 'qwerty123'], true)) {
        return 'Password terlalu mudah ditebak.';
    }
    return null;
}

/**
 * Verifikasi PIN supervisor (admin/superadmin) untuk otorisasi kasir.
 * @return array|null data supervisor bila PIN cocok
 */
function verify_supervisor_pin(string $pin, int $outletId): ?array
{
    if (!preg_match('/^\d{4,8}$/', $pin)) return null;
    if (login_is_locked('pin:' . $outletId)) return null;
    $rows = fetchAll(
        "SELECT id, nama, role, pin FROM users
         WHERE aktif = 1 AND deleted_at IS NULL AND pin IS NOT NULL
           AND (role = 'superadmin' OR (role = 'admin' AND outlet_id = ?))",
        [$outletId]
    );
    foreach ($rows as $r) {
        if (password_verify($pin, $r['pin'])) {
            unset($r['pin']);
            return $r;
        }
    }
    record_failed_login('pin:' . $outletId);
    return null;
}

/* ------------------------------------------------------------------
 *  Pembatasan percobaan login (brute-force)
 * ------------------------------------------------------------------ */
function login_is_locked(string $username): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $byUser = (int)fetchOne('SELECT COUNT(*) c FROM login_attempts WHERE username = ? AND created_at >= ?',
        [mb_strtolower($username), $since])['c'];
    $byIp = (int)fetchOne('SELECT COUNT(*) c FROM login_attempts WHERE ip = ? AND created_at >= ?',
        [client_ip(), $since])['c'];
    return $byUser >= LOGIN_MAX_ATTEMPTS || $byIp >= LOGIN_MAX_ATTEMPTS * 4;
}

function record_failed_login(string $username): void
{
    q('INSERT INTO login_attempts (username, ip) VALUES (?, ?)', [mb_strtolower(mb_substr($username, 0, 80)), client_ip()]);
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}

function clear_failed_logins(string $username): void
{
    q('DELETE FROM login_attempts WHERE username = ?', [mb_strtolower($username)]);
}

/* ------------------------------------------------------------------
 *  Login / logout
 * ------------------------------------------------------------------ */
/**
 * @return string|null null bila sukses, atau pesan error
 */
function attempt_login(string $username, string $password): ?string
{
    $username = trim($username);
    if (login_is_locked($username)) {
        return 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . LOGIN_LOCK_MINUTES . ' menit.';
    }
    $user = fetchOne('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1', [$username]);
    $hash = $user['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok = password_verify($password, $hash);
    if (!$user || !$ok || !$user['aktif']) {
        record_failed_login($username);
        return 'Username atau password salah, atau akun tidak aktif.';
    }
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        $user['password'] = fetchOne('SELECT password FROM users WHERE id = ?', [$user['id']])['password'];
    }
    clear_failed_logins($username);
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['pwd_sig'] = substr(hash('sha256', $user['password']), 0, 16);
    $_SESSION['weak_password'] = password_policy_error($password) !== null;

    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
    current_user(true);
    log_activity('login', 'Login berhasil', $user['outlet_id'] ? (int)$user['outlet_id'] : null, (int)$user['id']);
    return null;
}

/** Perbarui tanda tangan sesi setelah user mengganti password sendiri. */
function refresh_password_signature(int $userId): void
{
    $row = fetchOne('SELECT password FROM users WHERE id = ?', [$userId]);
    if ($row) $_SESSION['pwd_sig'] = substr(hash('sha256', $row['password']), 0, 16);
    $_SESSION['weak_password'] = false;
}

function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
