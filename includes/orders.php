<?php
/**
 * Logika transaksi bersama: validasi item, stok, diskon, shift, pembayaran.
 * Dipakai oleh api/checkout.php dan api/bill.php.
 */
require_once __DIR__ . '/auth.php';

class OrderError extends RuntimeException
{
    public array $extra;
    public int $http;
    public function __construct(string $msg, int $http = 422, array $extra = [])
    {
        parent::__construct($msg);
        $this->http = $http;
        $this->extra = $extra;
    }
}

/** Shift kasir yang sedang terbuka (per user per cabang). */
function current_shift(int $userId, int $outletId): ?array
{
    return fetchOne('SELECT * FROM shifts WHERE user_id = ? AND outlet_id = ? AND ditutup_at IS NULL ORDER BY id DESC LIMIT 1',
        [$userId, $outletId]);
}

function require_open_shift(array $user, int $outletId): array
{
    $shift = current_shift((int)$user['id'], $outletId);
    if (!$shift) {
        throw new OrderError('Shift kasir belum dibuka. Buka shift terlebih dahulu di menu Shift Kasir.', 409, ['need_shift' => true]);
    }
    return $shift;
}

/**
 * Validasi & kunci produk, hitung harga varian di server.
 * @return array{rows: array, qty: array<int,int>, subtotal: float}
 */
function build_order_items(PDO $pdo, int $outletId, array $items): array
{
    if (!$items) throw new OrderError('Keranjang masih kosong.', 400);
    if (count($items) > 200) throw new OrderError('Jumlah baris pesanan terlalu banyak.', 400);
    $ids = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid <= 0) throw new OrderError('Produk tidak valid.');
        $ids[$pid] = true;
    }
    $ids = array_keys($ids);
    sort($ids);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT p.id, p.nama, p.harga, p.harga_modal, p.lacak_stok, p.stok, p.varian, p.aktif, c.tipe
                         FROM products p LEFT JOIN categories c ON c.id = p.category_id
                         WHERE p.id IN ($ph) AND p.outlet_id = ? AND p.deleted_at IS NULL ORDER BY p.id FOR UPDATE");
    $st->execute(array_merge($ids, [$outletId]));
    $produk = [];
    foreach ($st->fetchAll() as $p) $produk[(int)$p['id']] = $p;

    $rows = [];
    $qty = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $p = $produk[$pid] ?? null;
        if (!$p || !$p['aktif']) throw new OrderError('Produk tidak tersedia pada cabang ini. Muat ulang halaman kasir.');
        $q = (int)($it['qty'] ?? 0);
        if ($q < 1 || $q > 999) throw new OrderError('Jumlah untuk "' . $p['nama'] . '" tidak valid.');
        $pilihan = is_array($it['pilihan'] ?? null) ? $it['pilihan'] : [];
        try {
            $hv = hitung_harga_varian((float)$p['harga'], $p['varian'], $pilihan);
        } catch (RuntimeException $e) {
            throw new OrderError($e->getMessage());
        }
        $sub = $hv['harga'] * $q;
        $subtotal += $sub;
        $qty[$pid] = ($qty[$pid] ?? 0) + $q;
        $rows[] = [
            'product_id'  => $pid,
            'nama_produk' => $p['nama'],
            'varian'      => mb_substr($hv['label'], 0, 150),
            'catatan'     => mb_substr(trim((string)($it['catatan'] ?? '')), 0, 150),
            'harga'       => $hv['harga'],
            'harga_modal' => (float)$p['harga_modal'],
            'qty'         => $q,
            'subtotal'    => $sub,
            'stasiun'     => in_array($p['tipe'], ['minuman', 'kopi'], true) ? 'bar' : 'dapur',
        ];
    }
    foreach ($qty as $pid => $q) {
        $p = $produk[$pid];
        if ($p['lacak_stok'] && (int)$p['stok'] < $q) {
            throw new OrderError('Stok "' . $p['nama'] . '" tidak cukup (tersisa ' . (int)$p['stok'] . ').');
        }
    }
    return ['rows' => $rows, 'qty' => $qty, 'subtotal' => $subtotal];
}

function insert_order_items(PDO $pdo, int $orderId, array $built, int $batch): void
{
    $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, nama_produk, varian, harga, harga_modal, qty, subtotal, catatan, stasiun, status_dapur, batch)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($built['rows'] as $r) {
        $ins->execute([$orderId, $r['product_id'], $r['nama_produk'], $r['varian'] ?: null, $r['harga'], $r['harga_modal'],
                       $r['qty'], $r['subtotal'], $r['catatan'] ?: null, $r['stasiun'], 'baru', $batch]);
    }
    $upd = $pdo->prepare('UPDATE products SET stok = stok - ? WHERE id = ? AND lacak_stok = 1');
    foreach ($built['qty'] as $pid => $q) $upd->execute([$q, $pid]);
}

function lock_table(PDO $pdo, ?int $tableId, int $outletId): void
{
    if (!$tableId) return;
    $st = $pdo->prepare('SELECT id FROM resto_tables WHERE id = ? AND outlet_id = ? FOR UPDATE');
    $st->execute([$tableId, $outletId]);
    if (!$st->fetch()) throw new OrderError('Meja tidak ditemukan pada cabang ini.');
}

function next_order_code(PDO $pdo, int $outletId): string
{
    $pdo->prepare('INSERT INTO order_counters (outlet_id, tanggal, last_no) VALUES (?, CURDATE(), LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')->execute([$outletId]);
    $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    $okode = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)fetchCol('SELECT kode FROM outlets WHERE id = ?', [$outletId]))) ?: 'TRX';
    return $okode . '-' . date('ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Otorisasi diskon sesuai peran & batas cabang.
 * @return array{persen: float, alasan: ?string, oleh: ?int}
 */
function authorize_discount(array $user, int $outletId, array $in, array $set): array
{
    $persen = round(min(100, max(0, (float)($in['diskon_persen'] ?? 0))), 2);
    $alasan = mb_substr(trim((string)($in['diskon_alasan'] ?? '')), 0, 150);
    $max = min(100, max(0, (float)$set['max_diskon_kasir']));
    if ($persen <= 0) return ['persen' => 0.0, 'alasan' => null, 'oleh' => null];
    $oleh = (int)$user['id'];
    if ($user['role'] === 'kasir' && $persen > $max) {
        $sup = verify_supervisor_pin((string)($in['supervisor_pin'] ?? ''), $outletId);
        if (!$sup) {
            throw new OrderError('Diskon di atas ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '% memerlukan PIN supervisor yang valid.', 403, ['need_pin' => true]);
        }
        $oleh = (int)$sup['id'];
    }
    if ($persen > $max && $alasan === '') {
        throw new OrderError('Alasan diskon wajib diisi untuk diskon di atas batas kasir.');
    }
    return ['persen' => $persen, 'alasan' => $alasan ?: null, 'oleh' => $oleh];
}

/**
 * Hitung & validasi pembayaran.
 * @return array{metode: string, bayar: float, kembalian: float, t: array}
 */
function compute_payment(float $subtotal, array $disc, array $set, array $in): array
{
    $metode = in_array($in['metode_bayar'] ?? '', ['cash', 'qris', 'debit', 'kredit', 'ewallet', 'transfer'], true) ? $in['metode_bayar'] : 'cash';
    $t = hitung_total($subtotal, $disc['persen'], (float)$set['service_persen'], (float)$set['pajak_persen']);
    $bayar = max(0, (float)($in['bayar'] ?? 0));
    if ($metode === 'cash') {
        if ($bayar + 0.001 < $t['total']) throw new OrderError('Jumlah bayar kurang dari total tagihan.');
        if ($bayar > max($t['total'] * 10, $t['total'] + 10000000)) throw new OrderError('Jumlah bayar tidak wajar. Periksa kembali.');
    } else {
        $bayar = $t['total'];
    }
    return ['metode' => $metode, 'bayar' => $bayar, 'kembalian' => round($bayar - $t['total'], 2), 't' => $t];
}

/** Simpan hasil pembayaran ke order (yang sudah ada). */
function apply_payment(PDO $pdo, int $orderId, array $pay, array $disc, array $set, array $user, ?array $shift, string $status): void
{
    $t = $pay['t'];
    $pdo->prepare('UPDATE orders SET status = ?, diskon_persen = ?, diskon_nilai = ?, diskon_oleh = ?, diskon_alasan = ?,
                     service_persen = ?, service_nilai = ?, pajak_persen = ?, pajak_nilai = ?, total = ?, bayar = ?, kembalian = ?,
                     metode_bayar = ?, lunas = 1, paid_at = NOW(), paid_by = ?, shift_id = ?
                   WHERE id = ?')
        ->execute([$status, $disc['persen'], $t['diskon'], $disc['oleh'], $disc['alasan'],
                   (float)$set['service_persen'], $t['service'], (float)$set['pajak_persen'], $t['pajak'], $t['total'],
                   $pay['bayar'], $pay['kembalian'], $pay['metode'], $user['id'], $shift['id'] ?? null, $orderId]);
}

/** Hitung ulang subtotal & estimasi total bill yang belum dibayar. */
function refresh_open_bill(PDO $pdo, int $orderId, array $set): void
{
    $sub = (float)$pdo->query('SELECT COALESCE(SUM(subtotal),0) FROM order_items WHERE order_id = ' . (int)$orderId)->fetchColumn();
    $t = hitung_total($sub, 0, (float)$set['service_persen'], (float)$set['pajak_persen']);
    $pdo->prepare('UPDATE orders SET subtotal = ?, service_persen = ?, service_nilai = ?, pajak_persen = ?, pajak_nilai = ?, total = ? WHERE id = ?')
        ->execute([$sub, (float)$set['service_persen'], $t['service'], (float)$set['pajak_persen'], $t['pajak'], $t['total'], $orderId]);
}

/** Kosongkan meja bila tidak ada pesanan berjalan lain. */
function release_table_if_free(PDO $pdo, ?int $tableId, int $outletId): void
{
    if (!$tableId) return;
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE table_id = ? AND status IN ('pending','proses')");
    $st->execute([$tableId]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("UPDATE resto_tables SET status = 'kosong' WHERE id = ? AND outlet_id = ?")->execute([$tableId, $outletId]);
    }
}

function log_discount(string $kode, array $disc, array $t, int $outletId): void
{
    if ($disc['persen'] > 0) {
        log_activity('diskon', sprintf('%s diskon %s%% (Rp %s) disetujui user #%d%s', $kode, $disc['persen'],
            number_format($t['diskon'], 0, ',', '.'), $disc['oleh'], $disc['alasan'] ? ' - ' . $disc['alasan'] : ''), $outletId);
    }
}

/** Ringkasan shift: penjualan per metode, kas masuk/keluar, kas seharusnya. */
function shift_summary(array $shift): array
{
    $perMetode = fetchAll("SELECT metode_bayar, COUNT(*) trx, COALESCE(SUM(total),0) total FROM orders
                           WHERE shift_id = ? AND lunas = 1 AND status <> 'batal' GROUP BY metode_bayar ORDER BY total DESC", [$shift['id']]);
    $batal = fetchOne("SELECT COUNT(*) trx, COALESCE(SUM(total),0) total FROM orders WHERE shift_id = ? AND status = 'batal'", [$shift['id']]);
    $diskon = (float)fetchCol("SELECT COALESCE(SUM(diskon_nilai),0) FROM orders WHERE shift_id = ? AND lunas = 1 AND status <> 'batal'", [$shift['id']]);
    $kas = fetchOne("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) masuk, COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) keluar
                     FROM shift_kas WHERE shift_id = ?", [$shift['id']]);
    $tunai = 0.0; $omzet = 0.0; $trx = 0;
    foreach ($perMetode as $m) {
        $omzet += (float)$m['total']; $trx += (int)$m['trx'];
        if ($m['metode_bayar'] === 'cash') $tunai = (float)$m['total'];
    }
    return [
        'per_metode' => $perMetode, 'omzet' => $omzet, 'trx' => $trx, 'tunai' => $tunai, 'diskon' => $diskon,
        'batal_trx' => (int)$batal['trx'], 'batal_total' => (float)$batal['total'],
        'kas_masuk' => (float)$kas['masuk'], 'kas_keluar' => (float)$kas['keluar'],
        'kas_seharusnya' => round((float)$shift['kas_awal'] + $tunai + (float)$kas['masuk'] - (float)$kas['keluar'], 2),
    ];
}

/** Cegah transaksi ganda (klik dua kali / koneksi putus). */
function find_duplicate_ref(int $userId, ?string $ref): ?array
{
    if (!$ref) return null;
    return fetchOne('SELECT o.id, o.kode, o.total, o.kembalian FROM activity_log a JOIN orders o ON o.kode = SUBSTRING_INDEX(a.deskripsi, " ", 1)
                     WHERE a.aksi = "checkout_ref" AND a.user_id = ? AND a.deskripsi LIKE ? LIMIT 1', [$userId, '% ref:' . $ref]) ?: null;
}

function client_ref(array $in): ?string
{
    return preg_match('/^[a-zA-Z0-9-]{8,64}$/', (string)($in['client_ref'] ?? '')) ? (string)$in['client_ref'] : null;
}

/** Inisialisasi endpoint JSON: metode, login, CSRF, cabang. */
function api_bootstrap(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
    if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Sesi berakhir, silakan login ulang.'], 401);
    if (is_role('dapur')) json_response(['ok' => false, 'message' => 'Akses ditolak.'], 403);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) json_response(['ok' => false, 'message' => 'Data tidak valid.'], 400);
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string)($in['_csrf'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'Token keamanan tidak valid, muat ulang halaman.'], 419);
    }
    $outletId = active_outlet_id();
    if (!$outletId) json_response(['ok' => false, 'message' => 'Pilih cabang aktif terlebih dahulu (superadmin).'], 400);
    return [$in, current_user(), $outletId];
}

function api_fail(Throwable $ex, PDO $pdo): void
{
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($ex instanceof OrderError) {
        json_response(array_merge(['ok' => false, 'message' => $ex->getMessage()], $ex->extra), $ex->http);
    }
    app_log('Order API error: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    json_response(['ok' => false, 'message' => 'Transaksi gagal diproses. Silakan coba lagi.'], 500);
}
