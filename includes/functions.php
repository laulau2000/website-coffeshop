<?php
/**
 * Helper umum aplikasi KasirKu
 */
require_once __DIR__ . '/../config/config.php';

/* ------------------------------------------------------------------
 *  Database
 * ------------------------------------------------------------------ */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            if (PHP_SAPI !== 'cli' && !is_file(INSTALL_LOCK) && (int)$e->getCode() === 1049
                && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
                header('Location: ' . BASE_URL . 'install.php');
                exit;
            }
            app_log('DB connection failed: ' . $e->getMessage());
            fatal_page(
                'Koneksi database gagal',
                APP_DEBUG ? $e->getMessage() : 'Server database tidak dapat dihubungi. Hubungi administrator sistem.'
            );
        }
        require_once __DIR__ . '/migrate.php';
        ensure_schema_up_to_date($pdo);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetchOne(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function fetchAll(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetchCol(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

/* ------------------------------------------------------------------
 *  Output & keamanan
 * ------------------------------------------------------------------ */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Redirect ke path internal aplikasi (URL eksternal ditolak). */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . ltrim(safe_internal_path($path), '/'));
    exit;
}

/** Pastikan path relatif di dalam aplikasi (cegah open redirect). */
function safe_internal_path(string $path, string $fallback = 'index.php'): string
{
    $path = trim($path);
    if (str_starts_with($path, BASE_URL) && BASE_URL !== '/') {
        $path = substr($path, strlen(BASE_URL));
    }
    if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//')
        || str_contains($path, '\\') || preg_match('/[\r\n]/', $path)) {
        return $fallback;
    }
    return ltrim($path, '/');
}

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/* ------------------------------------------------------------------
 *  CSRF
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        fatal_page('Sesi kadaluarsa', 'Token keamanan tidak valid. Silakan kembali dan muat ulang halaman.', 419);
    }
}

/* ------------------------------------------------------------------
 *  Flash message (toast)
 * ------------------------------------------------------------------ */
function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flash(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/* ------------------------------------------------------------------
 *  Format
 * ------------------------------------------------------------------ */
function rupiah($angka): string
{
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function angka($angka, int $desimal = 0): string
{
    return number_format((float)$angka, $desimal, ',', '.');
}

/** Label meja tanpa dobel kata "Meja" (mis. nama meja "Meja 01"). */
function meja_label(?string $nama): string
{
    $nama = trim((string)$nama);
    if ($nama === '') return '';
    return preg_match('/^meja\b/i', $nama) ? $nama : 'Meja ' . $nama;
}

function hari_indo($date): string
{
    $hari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    return $hari[(int)date('w', strtotime((string)$date))];
}

function tanggal_indo($datetime, bool $dengan_jam = true): string
{
    if (!$datetime) return '-';
    $bulan = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($datetime);
    $hasil = date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($dengan_jam) $hasil .= ' ' . date('H:i', $ts);
    return $hasil;
}

function waktu_lalu($datetime): string
{
    if (!$datetime) return '-';
    $selisih = time() - strtotime($datetime);
    if ($selisih < 60)      return 'baru saja';
    if ($selisih < 3600)    return floor($selisih / 60) . ' menit lalu';
    if ($selisih < 86400)   return floor($selisih / 3600) . ' jam lalu';
    if ($selisih < 604800)  return floor($selisih / 86400) . ' hari lalu';
    return tanggal_indo($datetime, false);
}

/* ------------------------------------------------------------------
 *  JSON response (untuk endpoint AJAX)
 * ------------------------------------------------------------------ */
function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------
 *  Terbilang (untuk struk)
 * ------------------------------------------------------------------ */
function terbilang($angka): string
{
    $angka = abs((int)$angka);
    $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($angka < 12) {
        return $satuan[$angka];
    } elseif ($angka < 20) {
        return terbilang($angka - 10) . ' belas';
    } elseif ($angka < 100) {
        return terbilang(intdiv($angka, 10)) . ' puluh ' . terbilang($angka % 10);
    } elseif ($angka < 200) {
        return 'seratus ' . terbilang($angka - 100);
    } elseif ($angka < 1000) {
        return terbilang(intdiv($angka, 100)) . ' ratus ' . terbilang($angka % 100);
    } elseif ($angka < 2000) {
        return 'seribu ' . terbilang($angka - 1000);
    } elseif ($angka < 1000000) {
        return terbilang(intdiv($angka, 1000)) . ' ribu ' . terbilang($angka % 1000);
    } elseif ($angka < 1000000000) {
        return terbilang(intdiv($angka, 1000000)) . ' juta ' . terbilang($angka % 1000000);
    }
    return terbilang(intdiv($angka, 1000000000)) . ' miliar ' . terbilang($angka % 1000000000);
}

/* ------------------------------------------------------------------
 *  Outlet aktif (multi-tenant)
 * ------------------------------------------------------------------ */
function active_outlet_id(): ?int
{
    $u = current_user();
    if (!$u) return null;
    // Superadmin dapat berpindah outlet lewat sesi
    if ($u['role'] === 'superadmin') {
        return isset($_SESSION['outlet_aktif']) ? (int)$_SESSION['outlet_aktif'] : null;
    }
    return $u['outlet_id'] !== null ? (int)$u['outlet_id'] : null;
}

/** Mengembalikan [clause SQL, params] untuk membatasi query per outlet. */
function outlet_scope(string $column = 'outlet_id'): array
{
    $u = current_user();
    if ($u && $u['role'] === 'superadmin') {
        $oid = active_outlet_id();
        if ($oid) {
            return [" AND $column = ?", [$oid]];
        }
        return ['', []];
    }
    return [" AND $column = ?", [active_outlet_id()]];
}

/* ------------------------------------------------------------------
 *  Varian produk  <->  teks
 *  Format teks:
 *    Suhu: Hot, Ice(+3000)
 *    Size: Reguler, Large(+5000)
 * ------------------------------------------------------------------ */
function varian_to_json(string $text): ?string
{
    $groups = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) continue;
        [$nama, $opsiStr] = explode(':', $line, 2);
        $opsi = [];
        foreach (explode(',', $opsiStr) as $o) {
            $o = trim($o);
            if ($o === '') continue;
            $tambahan = 0;
            $label = $o;
            if (preg_match('/^(.*?)\(\s*\+?\s*([0-9.]+)\s*\)\s*$/', $o, $m)) {
                $label = trim($m[1]);
                $tambahan = (float)str_replace('.', '', $m[2]);
            }
            $opsi[] = ['label' => $label, 'tambahan' => $tambahan];
        }
        if ($opsi) $groups[] = ['nama' => trim($nama), 'opsi' => $opsi];
    }
    return $groups ? json_encode($groups, JSON_UNESCAPED_UNICODE) : null;
}

/**
 * Hitung harga satuan + label varian dari pilihan indeks (sisi server).
 * $pilihan: [indeks_opsi_grup0, indeks_opsi_grup1, ...]
 * @return array{harga: float, label: string}
 */
function hitung_harga_varian(float $hargaDasar, ?string $varianJson, array $pilihan): array
{
    $groups = $varianJson ? json_decode($varianJson, true) : [];
    if (!is_array($groups)) $groups = [];
    foreach (array_keys($pilihan) as $k) {
        if (!is_int($k) || !isset($groups[$k])) {
            throw new RuntimeException('Pilihan varian tidak valid.');
        }
    }
    $harga = $hargaDasar;
    $labels = [];
    foreach ($groups as $gi => $g) {
        $opsi = $g['opsi'] ?? [];
        if (!$opsi) continue;
        $oi = isset($pilihan[$gi]) ? (int)$pilihan[$gi] : 0;
        if (!isset($opsi[$oi])) {
            throw new RuntimeException('Pilihan varian tidak valid.');
        }
        $harga += max(0, (float)($opsi[$oi]['tambahan'] ?? 0));
        $labels[] = (string)$opsi[$oi]['label'];
    }
    return ['harga' => round($harga, 2), 'label' => implode(', ', $labels)];
}

/**
 * Perhitungan total transaksi (dipakai server & sebagai acuan JS).
 * Urutan: subtotal -> diskon -> service charge -> pajak (PB1 atas subtotal+service).
 */
function hitung_total(float $subtotal, float $diskonPersen, float $servicePersen, float $pajakPersen): array
{
    $diskon  = round($subtotal * $diskonPersen / 100);
    $dasar   = $subtotal - $diskon;
    $service = round($dasar * $servicePersen / 100);
    $pajak   = round(($dasar + $service) * $pajakPersen / 100);
    $total   = round($dasar + $service + $pajak, 2);
    return ['diskon' => $diskon, 'service' => $service, 'pajak' => $pajak, 'total' => $total];
}

function varian_to_text(?string $json): string
{
    if (!$json) return '';
    $groups = json_decode($json, true);
    if (!is_array($groups)) return '';
    $lines = [];
    foreach ($groups as $g) {
        $opts = [];
        foreach (($g['opsi'] ?? []) as $o) {
            $opts[] = ((float)($o['tambahan'] ?? 0) > 0)
                ? $o['label'] . '(+' . angka($o['tambahan']) . ')'
                : $o['label'];
        }
        $lines[] = ($g['nama'] ?? 'Varian') . ': ' . implode(', ', $opts);
    }
    return implode("\n", $lines);
}


/* ------------------------------------------------------------------
 *  Pengaturan outlet
 * ------------------------------------------------------------------ */
function outlet_settings(?int $outletId): array
{
    $row = $outletId ? fetchOne('SELECT * FROM settings WHERE outlet_id = ?', [$outletId]) : null;
    return array_merge([
        'nama_resto' => null, 'alamat' => null, 'telepon' => null, 'logo' => null,
        'pajak_persen' => 0, 'service_persen' => 0, 'max_diskon_kasir' => 10,
        'lebar_struk' => 58, 'footer_struk' => 'Terima kasih atas kunjungan Anda', 'qris_image' => null,
    ], $row ?: []);
}

/* ------------------------------------------------------------------
 *  Upload gambar aman (cek isi file, bukan hanya ekstensi)
 * ------------------------------------------------------------------ */
function save_uploaded_image(string $field, string $prefix, int $maxBytes = 2097152): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('Upload gambar gagal. Coba lagi.');
    }
    if ($f['size'] > $maxBytes) {
        throw new RuntimeException('Ukuran gambar maksimal ' . round($maxBytes / 1048576) . 'MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('File harus berupa gambar JPG, PNG, WEBP, atau GIF.');
    }
    if (!is_dir(UPLOAD_PATH)) @mkdir(UPLOAD_PATH, 0775, true);
    $name = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_PATH . '/' . $name)) {
        throw new RuntimeException('Gagal menyimpan gambar. Periksa izin folder uploads/.');
    }
    return $name;
}

function delete_upload(?string $name): void
{
    if ($name && basename($name) === $name && is_file(UPLOAD_PATH . '/' . $name)) {
        @unlink(UPLOAD_PATH . '/' . $name);
    }
}

/* ------------------------------------------------------------------
 *  Audit log
 * ------------------------------------------------------------------ */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function log_activity(string $aksi, string $deskripsi, ?int $outletId = null, ?int $userId = null): void
{
    try {
        if ($userId === null && function_exists('current_user')) {
            $u = current_user();
            $userId = $u ? (int)$u['id'] : null;
        }
        if ($outletId === null && function_exists('active_outlet_id')) {
            $outletId = active_outlet_id();
        }
        q('INSERT INTO activity_log (outlet_id, user_id, aksi, deskripsi, ip) VALUES (?,?,?,?,?)',
          [$outletId, $userId, mb_substr($aksi, 0, 40), mb_substr($deskripsi, 0, 500), client_ip()]);
    } catch (Throwable $e) {
        app_log('activity_log gagal: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 *  Log aplikasi & halaman error
 * ------------------------------------------------------------------ */
function app_log(string $message): void
{
    $dir = STORAGE_PATH . '/logs';
    $file = $dir . '/app-' . date('Y-m') . '.log.php';
    if (is_dir($dir) && is_writable($dir)) {
        if (!is_file($file)) @file_put_contents($file, "<?php exit; ?>\n");
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log('[KasirKu] ' . $message);
    }
}

function fatal_page(string $title, string $message, int $code = 500): void
{
    if (!headers_sent()) http_response_code($code);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title><link rel="stylesheet" href="' . e(BASE_URL) . 'assets/css/app.css"></head>'
        . '<body><div class="login-page"><div class="login-card text-center"><h2>' . e($title) . '</h2><p>' . e($message) . '</p>'
        . '<a class="btn btn-primary" href="' . e(BASE_URL) . 'index.php">Kembali</a></div></div></body></html>';
    exit;
}

if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e) {
        app_log(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        fatal_page('Terjadi kesalahan', APP_DEBUG ? $e->getMessage() : 'Terjadi kesalahan pada sistem. Silakan coba lagi atau hubungi administrator.');
    });
}

/* ------------------------------------------------------------------
 *  CSV aman (cegah formula injection di Excel)
 * ------------------------------------------------------------------ */
function csv_cell($v)
{
    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) {
        return "'" . $v;
    }
    return $v;
}

function csv_row($handle, array $row): void
{
    fputcsv($handle, array_map('csv_cell', $row));
}

/* ------------------------------------------------------------------
 *  Ikon SVG (pengganti emoji agar tampil sama di semua perangkat)
 * ------------------------------------------------------------------ */
function icon(string $name, int $size = 18): string
{
    $paths = [
        'cup'     => '<path d="M4 8h13v5a6 6 0 0 1-6 6h-1a6 6 0 0 1-6-6V8z"/><path d="M17 10h1.5a2.5 2.5 0 0 1 0 5H17"/><path d="M8 2v3M12 2v3"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>',
        'cart'    => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L21 8H6"/>',
        'chart'   => '<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/>',
        'printer' => '<path d="M6 9V2h12v7"/><rect x="2" y="9" width="20" height="9" rx="2"/><path d="M6 14h12v8H6z"/>',
        'table'   => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18"/>',
        'box'     => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'check'   => '<path d="M20 6L9 17l-5-5"/>',
        'pos'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h8"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['cup']) . '</svg>';
}

function brand_initials(): string
{
    $words = preg_split('/\s+/', trim(APP_NAME));
    $ini = '';
    foreach ($words as $w) { if ($w !== '') $ini .= mb_substr($w, 0, 1); }
    if (mb_strlen($ini) < 2) $ini = mb_substr(APP_NAME, 0, 2);
    return mb_strtoupper(mb_substr($ini, 0, 2));
}

function favicon_tag(): string
{
    return '<link rel="icon" href="data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#e8a33d"/><text x="32" y="42" font-family="Arial" font-size="26" font-weight="700" text-anchor="middle" fill="#2a1a05">' . htmlspecialchars(brand_initials()) . '</text></svg>') . '">';
}
