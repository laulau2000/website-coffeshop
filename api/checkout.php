<?php
/**
 * Endpoint transaksi baru dari halaman kasir.
 *   mode = "bayar"  : buat pesanan & bayar langsung (wajib shift terbuka)
 *   mode = "simpan" : buat open bill (bayar nanti), meja ditandai terisi
 * Semua harga, varian, pajak, service, dan stok dihitung ulang di server.
 */
require_once __DIR__ . '/../includes/orders.php';

[$in, $user, $outletId] = api_bootstrap();
$mode    = ($in['mode'] ?? 'bayar') === 'simpan' ? 'simpan' : 'bayar';
$tipe    = in_array($in['tipe'] ?? '', ['dine_in', 'take_away', 'delivery'], true) ? $in['tipe'] : 'dine_in';
$tableId = ($tipe === 'dine_in' && !empty($in['table_id'])) ? (int)$in['table_id'] : null;
$ref     = client_ref($in);
$set     = outlet_settings($outletId);

if ($dup = find_duplicate_ref((int)$user['id'], $ref)) {
    json_response(['ok' => true, 'id' => (int)$dup['id'], 'kode' => $dup['kode'], 'total' => (float)$dup['total'],
                   'kembalian' => (float)$dup['kembalian'], 'duplicate' => true]);
}

$pdo = db();
try {
    $shift = $mode === 'bayar' ? require_open_shift($user, $outletId) : null;
    $disc  = $mode === 'bayar' ? authorize_discount($user, $outletId, $in, $set) : ['persen' => 0.0, 'alasan' => null, 'oleh' => null];
    if ($mode === 'simpan' && !$tableId && trim((string)($in['nama_pelanggan'] ?? '')) === '') {
        throw new OrderError('Open bill wajib memilih meja atau mengisi nama pelanggan.');
    }

    $pdo->beginTransaction();
    $built = build_order_items($pdo, $outletId, is_array($in['items'] ?? null) ? $in['items'] : []);
    lock_table($pdo, $tableId, $outletId);
    $kode = next_order_code($pdo, $outletId);

    $pdo->prepare('INSERT INTO orders (outlet_id, user_id, table_id, kode, tipe, status, subtotal, lunas, nama_pelanggan, catatan)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$outletId, $user['id'], $tableId, $kode, $tipe, $mode === 'simpan' || $tableId ? 'proses' : 'selesai',
                   $built['subtotal'], $mode === 'bayar' ? 1 : 0,
                   mb_substr(trim((string)($in['nama_pelanggan'] ?? '')), 0, 100),
                   mb_substr(trim((string)($in['catatan'] ?? '')), 0, 255)]);
    $orderId = (int)$pdo->lastInsertId();
    insert_order_items($pdo, $orderId, $built, 1);

    $pay = null;
    if ($mode === 'bayar') {
        $pay = compute_payment($built['subtotal'], $disc, $set, $in);
        // Dine-in dengan meja: status proses (meja terisi) sampai ditandai selesai
        apply_payment($pdo, $orderId, $pay, $disc, $set, $user, $shift, $tableId ? 'proses' : 'selesai');
    } else {
        refresh_open_bill($pdo, $orderId, $set);
    }
    if ($tableId) {
        $pdo->prepare("UPDATE resto_tables SET status = 'terisi' WHERE id = ? AND outlet_id = ?")->execute([$tableId, $outletId]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    api_fail($ex, $pdo);
}

if ($ref) log_activity('checkout_ref', $kode . ' ref:' . $ref, $outletId);
if ($pay) log_discount($kode, $disc, $pay['t'], $outletId);
if ($mode === 'simpan') log_activity('open_bill', 'Buka bill ' . $kode, $outletId);
json_response(['ok' => true, 'id' => $orderId, 'kode' => $kode, 'mode' => $mode,
               'total' => $pay['t']['total'] ?? null, 'kembalian' => $pay['kembalian'] ?? 0]);
