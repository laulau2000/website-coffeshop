<?php
/**
 * Operasi open bill (pesanan belum dibayar).
 *   action = tambah  : tambah item ke bill  {order_id, items}
 *   action = bayar   : bayar bill, opsional sebagian/split {order_id, split:[{item_id, qty}], metode_bayar, bayar, diskon...}
 *   action = pindah  : pindah meja {order_id, table_id}
 *   action = gabung  : gabungkan bill ke bill lain {order_id, target_id}
 */
require_once __DIR__ . '/../includes/orders.php';

[$in, $user, $outletId] = api_bootstrap();
$action  = (string)($in['action'] ?? '');
$orderId = (int)($in['order_id'] ?? 0);
$set     = outlet_settings($outletId);
$ref     = client_ref($in);

function lock_open_bill(PDO $pdo, int $orderId, int $outletId): array
{
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND outlet_id = ? FOR UPDATE');
    $st->execute([$orderId, $outletId]);
    $o = $st->fetch();
    if (!$o) throw new OrderError('Bill tidak ditemukan pada cabang ini.', 404);
    if ($o['status'] === 'batal' || (int)$o['lunas'] === 1) throw new OrderError('Bill ' . $o['kode'] . ' sudah dibayar atau dibatalkan.');
    return $o;
}

if ($action === 'bayar' && ($dup = find_duplicate_ref((int)$user['id'], $ref))) {
    json_response(['ok' => true, 'id' => (int)$dup['id'], 'kode' => $dup['kode'], 'total' => (float)$dup['total'],
                   'kembalian' => (float)$dup['kembalian'], 'duplicate' => true]);
}

$pdo = db();
$result = ['ok' => true];
$logs = [];
try {
    if ($action === 'bayar') {
        $shift = require_open_shift($user, $outletId);
        $disc  = authorize_discount($user, $outletId, $in, $set);
    }
    $pdo->beginTransaction();
    $bill = lock_open_bill($pdo, $orderId, $outletId);

    if ($action === 'tambah') {
        $built = build_order_items($pdo, $outletId, is_array($in['items'] ?? null) ? $in['items'] : []);
        $batch = (int)$pdo->query('SELECT COALESCE(MAX(batch),0) + 1 FROM order_items WHERE order_id = ' . $orderId)->fetchColumn();
        insert_order_items($pdo, $orderId, $built, $batch);
        refresh_open_bill($pdo, $orderId, $set);
        $result += ['id' => $orderId, 'kode' => $bill['kode'], 'batch' => $batch];
        $logs[] = ['bill_tambah', 'Tambah ' . count($built['rows']) . ' item ke ' . $bill['kode']];

    } elseif ($action === 'bayar') {
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? FOR UPDATE');
        $items->execute([$orderId]);
        $items = $items->fetchAll();
        if (!$items) throw new OrderError('Bill tidak memiliki item.');
        $byId = [];
        foreach ($items as $it) $byId[(int)$it['id']] = $it;

        $split = is_array($in['split'] ?? null) ? $in['split'] : [];
        $pilih = [];
        foreach ($split as $sp) {
            $iid = (int)($sp['item_id'] ?? 0);
            $q = (int)($sp['qty'] ?? 0);
            if (!isset($byId[$iid]) || $q < 1 || $q > (int)$byId[$iid]['qty'] || isset($pilih[$iid])) {
                throw new OrderError('Pilihan item untuk split bill tidak valid.');
            }
            $pilih[$iid] = $q;
        }
        $semua = !$pilih || (count($pilih) === count($byId) && !array_filter($pilih, fn($q, $iid) => $q < (int)$byId[$iid]['qty'], ARRAY_FILTER_USE_BOTH));

        if ($semua) {
            $payId = $orderId;
            $payKode = $bill['kode'];
            $subtotal = array_sum(array_map(fn($it) => (float)$it['subtotal'], $items));
        } else {
            // Split: pindahkan item terpilih ke pesanan baru lalu bayar pesanan baru itu
            $payKode = next_order_code($pdo, $outletId);
            $pdo->prepare('INSERT INTO orders (outlet_id, user_id, table_id, kode, tipe, status, lunas, nama_pelanggan, catatan)
                           VALUES (?,?,?,?,?,?,0,?,?)')
                ->execute([$outletId, $user['id'], $bill['table_id'], $payKode, $bill['tipe'], 'selesai',
                           $bill['nama_pelanggan'], 'Split dari ' . $bill['kode']]);
            $payId = (int)$pdo->lastInsertId();
            $subtotal = 0.0;
            $move = $pdo->prepare('UPDATE order_items SET order_id = ? WHERE id = ?');
            $reduce = $pdo->prepare('UPDATE order_items SET qty = qty - ?, subtotal = harga * (qty) WHERE id = ?');
            $copy = $pdo->prepare('INSERT INTO order_items (order_id, product_id, nama_produk, varian, harga, harga_modal, qty, subtotal, catatan, stasiun, status_dapur, batch, created_at)
                                   SELECT ?, product_id, nama_produk, varian, harga, harga_modal, ?, harga * ?, catatan, stasiun, status_dapur, batch, created_at FROM order_items WHERE id = ?');
            foreach ($pilih as $iid => $q) {
                $it = $byId[$iid];
                if ($q === (int)$it['qty']) {
                    $move->execute([$payId, $iid]);
                } else {
                    $copy->execute([$payId, $q, $q, $iid]);
                    $reduce->execute([$q, $iid]);
                }
                $subtotal += (float)$it['harga'] * $q;
            }
            $pdo->prepare('UPDATE orders SET subtotal = ? WHERE id = ?')->execute([$subtotal, $payId]);
            refresh_open_bill($pdo, $orderId, $set);
        }
        $pay = compute_payment($subtotal, $disc, $set, $in);
        $pdo->prepare('UPDATE orders SET subtotal = ? WHERE id = ?')->execute([$subtotal, $payId]);
        apply_payment($pdo, $payId, $pay, $disc, $set, $user, $shift, 'selesai');
        if ($semua) release_table_if_free($pdo, $bill['table_id'] ? (int)$bill['table_id'] : null, $outletId);
        $result += ['id' => $payId, 'kode' => $payKode, 'total' => $pay['t']['total'], 'kembalian' => $pay['kembalian'], 'split' => !$semua,
                    'sisa_bill' => $semua ? null : $orderId];
        $logs[] = ['bill_bayar', ($semua ? 'Bayar bill ' : 'Split bill ' . $bill['kode'] . ' -> ') . $payKode . ' Rp ' . number_format($pay['t']['total'], 0, ',', '.')];

    } elseif ($action === 'pindah') {
        $tableId = (int)($in['table_id'] ?? 0);
        lock_table($pdo, $tableId, $outletId);
        if ($tableId === (int)$bill['table_id']) throw new OrderError('Meja tujuan sama dengan meja sekarang.');
        $pdo->prepare("UPDATE orders SET table_id = ?, tipe = 'dine_in' WHERE id = ?")->execute([$tableId, $orderId]);
        $pdo->prepare("UPDATE resto_tables SET status = 'terisi' WHERE id = ?")->execute([$tableId]);
        release_table_if_free($pdo, $bill['table_id'] ? (int)$bill['table_id'] : null, $outletId);
        $nama = fetchCol('SELECT nama FROM resto_tables WHERE id = ?', [$tableId]);
        $result += ['id' => $orderId];
        $logs[] = ['bill_pindah', $bill['kode'] . ' pindah ke meja ' . $nama];

    } elseif ($action === 'gabung') {
        $targetId = (int)($in['target_id'] ?? 0);
        if ($targetId === $orderId) throw new OrderError('Pilih bill lain untuk digabung.');
        $target = lock_open_bill($pdo, $targetId, $outletId);
        $pdo->prepare('UPDATE order_items SET order_id = ? WHERE order_id = ?')->execute([$targetId, $orderId]);
        $pdo->prepare("UPDATE orders SET status = 'batal', subtotal = 0, total = 0, service_nilai = 0, pajak_nilai = 0,
                         batal_oleh = ?, batal_alasan = ?, batal_at = NOW() WHERE id = ?")
            ->execute([$user['id'], 'Digabung ke ' . $target['kode'], $orderId]);
        refresh_open_bill($pdo, $targetId, $set);
        release_table_if_free($pdo, $bill['table_id'] ? (int)$bill['table_id'] : null, $outletId);
        $result += ['id' => $targetId, 'kode' => $target['kode']];
        $logs[] = ['bill_gabung', $bill['kode'] . ' digabung ke ' . $target['kode']];

    } else {
        throw new OrderError('Aksi tidak dikenal.', 400);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    api_fail($ex, $pdo);
}

foreach ($logs as [$a, $d]) log_activity($a, $d, $outletId);
if ($action === 'bayar') {
    if ($ref) log_activity('checkout_ref', $result['kode'] . ' ref:' . $ref, $outletId);
    log_discount($result['kode'], $disc, $pay['t'], $outletId);
}
json_response($result);
