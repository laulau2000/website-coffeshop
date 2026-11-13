<?php
/**
 * KDS (Kitchen Display): GET = daftar pesanan aktif, POST = ubah status item.
 */
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Sesi berakhir.'], 401);
$outletId = active_outlet_id();
if (!$outletId) json_response(['ok' => false, 'message' => 'Pilih cabang aktif terlebih dahulu.'], 400);

$urutan = ['baru' => 'dimasak', 'dimasak' => 'siap', 'siap' => 'diantar'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string)($in['_csrf'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'Token keamanan tidak valid, muat ulang halaman.'], 419);
    }
    $ids = array_values(array_filter(array_map('intval', (array)($in['item_ids'] ?? [])), fn($v) => $v > 0));
    if (!$ids || count($ids) > 100) json_response(['ok' => false, 'message' => 'Item tidak valid.'], 400);
    $to = (string)($in['status'] ?? '');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = fetchAll("SELECT oi.id, oi.status_dapur FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.id IN ($ph) AND o.outlet_id = ? AND o.status <> 'batal'", array_merge($ids, [$outletId]));
    $n = 0;
    foreach ($rows as $r) {
        $next = $to !== '' && in_array($to, ['baru', 'dimasak', 'siap', 'diantar'], true) ? $to : ($urutan[$r['status_dapur']] ?? null);
        if ($next) { q('UPDATE order_items SET status_dapur = ? WHERE id = ?', [$next, $r['id']]); $n++; }
    }
    json_response(['ok' => true, 'updated' => $n]);
}

$stasiun = in_array($_GET['stasiun'] ?? '', ['dapur', 'bar'], true) ? $_GET['stasiun'] : null;
$items = fetchAll(
    "SELECT oi.id, oi.order_id, oi.nama_produk, oi.varian, oi.catatan, oi.qty, oi.stasiun, oi.status_dapur, oi.batch, oi.created_at,
            o.kode, o.tipe, o.nama_pelanggan, rt.nama meja
     FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN resto_tables rt ON rt.id = o.table_id
     WHERE o.outlet_id = ? AND o.status <> 'batal' AND oi.status_dapur IN ('baru','dimasak','siap')
       AND oi.created_at >= NOW() - INTERVAL 18 HOUR" . ($stasiun ? ' AND oi.stasiun = ?' : '') . "
     ORDER BY oi.created_at, oi.id LIMIT 300",
    $stasiun ? [$outletId, $stasiun] : [$outletId]
);
$tickets = [];
foreach ($items as $it) {
    $key = $it['order_id'] . '-' . $it['batch'];
    if (!isset($tickets[$key])) {
        $tickets[$key] = ['key' => $key, 'kode' => $it['kode'], 'batch' => (int)$it['batch'], 'meja' => $it['meja'],
                          'tipe' => $it['tipe'], 'pelanggan' => $it['nama_pelanggan'], 'waktu' => $it['created_at'],
                          'menit' => (int)floor((time() - strtotime($it['created_at'])) / 60), 'items' => []];
    }
    $tickets[$key]['items'][] = ['id' => (int)$it['id'], 'nama' => $it['nama_produk'], 'varian' => $it['varian'], 'catatan' => $it['catatan'],
                                 'qty' => (int)$it['qty'], 'stasiun' => $it['stasiun'], 'status' => $it['status_dapur']];
}
json_response(['ok' => true, 'tickets' => array_values($tickets), 'server_time' => date('H:i:s')]);
