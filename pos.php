<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_role('superadmin', 'admin', 'kasir');
$outletIdPos = require_outlet();
$billId = (int)get('bill', 0);
$bill = null;
if ($billId) {
    $bill = fetchOne("SELECT o.*, rt.nama meja FROM orders o LEFT JOIN resto_tables rt ON rt.id = o.table_id
                      WHERE o.id = ? AND o.outlet_id = ? AND o.lunas = 0 AND o.status <> 'batal'", [$billId, $outletIdPos]);
    if (!$bill) { set_flash('danger', 'Bill tidak ditemukan atau sudah dibayar.'); redirect('bills.php'); }
    $billItems = fetchAll('SELECT nama_produk, varian, qty, subtotal FROM order_items WHERE order_id = ? ORDER BY id', [$billId]);
}
$hasShift = (bool)current_shift((int)current_user()['id'], $outletIdPos);

[$ps, $psp] = outlet_scope('p.outlet_id');
$produk = fetchAll(
    "SELECT p.id, p.nama, p.harga, p.stok, p.lacak_stok, p.satuan, p.gambar, p.varian, p.favorit,
            c.nama AS kategori, c.tipe, p.category_id
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.aktif = 1 AND p.deleted_at IS NULL" . $ps . "
     ORDER BY c.urutan, p.nama",
    $psp
);

$kategori = fetchAll(
    'SELECT id, nama, tipe FROM categories WHERE aktif = 1' . outlet_scope('outlet_id')[0] . ' ORDER BY urutan, nama',
    outlet_scope('outlet_id')[1]
);

[$ts, $tsp] = outlet_scope('outlet_id');
$mejaList = fetchAll('SELECT id, nama, area, status FROM resto_tables WHERE 1=1' . $ts . " ORDER BY area, nama", $tsp);

$setting = outlet_settings(active_outlet_id());
$pajakPersen   = (float)$setting['pajak_persen'];
$servicePersen = (float)$setting['service_persen'];
$maxDiskon     = is_role('kasir') ? (float)$setting['max_diskon_kasir'] : 100;
$fmtPersen = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');

// Data untuk JS
$produkJs = array_map(function ($p) {
    return [
        'id'        => (int)$p['id'],
        'nama'      => $p['nama'],
        'harga'     => (float)$p['harga'],
        'stok'      => $p['lacak_stok'] ? (int)$p['stok'] : null,
        'satuan'    => $p['satuan'],
        'gambar'    => $p['gambar'] ? UPLOAD_URL . $p['gambar'] : null,
        'kategori'  => $p['kategori'] ?: 'Lainnya',
        'kat_id'    => (int)$p['category_id'],
        'favorit'   => (int)$p['favorit'],
        'varian'    => $p['varian'] ? json_decode($p['varian'], true) : [],
    ];
}, $produk);

$page_title = 'Kasir / POS';
$page_sub   = 'Pilih menu lalu proses pembayaran';
$active     = 'pos.php';
include __DIR__ . '/includes/header.php';
?>
<?php if ($bill): ?>
  <div class="alert alert-info no-print" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:200px">Menambah pesanan ke bill <strong><?= e($bill['meja'] ? meja_label($bill['meja']) : ($bill['nama_pelanggan'] ?: $bill['kode'])) ?></strong> (<?= e($bill['kode']) ?>).
      Sudah ada: <?= e(implode(', ', array_map(fn($i) => $i['qty'] . 'x ' . $i['nama_produk'], $billItems))) ?>.</div>
    <a class="btn btn-light btn-sm" href="<?= e(BASE_URL) ?>bill.php?id=<?= (int)$bill['id'] ?>">Batal tambah</a>
  </div>
<?php elseif (!$hasShift): ?>
  <div class="alert alert-warning no-print">Shift Anda belum dibuka: pembayaran belum bisa diproses (open bill tetap bisa disimpan). <a href="<?= e(BASE_URL) ?>shift.php"><strong>Buka shift sekarang</strong></a></div>
<?php endif; ?>

<div class="pos-layout">
  <!-- ============ Katalog ============ -->
  <div>
    <div class="pos-toolbar">
      <div class="search-box" style="flex:1;min-width:220px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
        <input class="input" type="text" id="search-produk" placeholder="Cari menu... (nama / kategori)">
      </div>
      <button class="btn btn-light btn-sm" id="filter-favorit" type="button">&#9733; Favorit</button>
      <button class="btn btn-light btn-sm" id="btn-held" type="button" title="Pesanan yang ditahan">Ditahan <span class="badge badge-warning hidden" id="held-count">0</span></button>
    </div>

    <div class="cat-chips" id="cat-chips">
      <button class="cat-chip active" data-kat="all" type="button">Semua</button>
      <?php foreach ($kategori as $k): ?>
        <button class="cat-chip" data-kat="<?= (int)$k['id'] ?>" type="button"><?= e($k['nama']) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="prod-grid" id="prod-grid"></div>
    <div class="empty-state hidden" id="prod-empty"><div class="es-ico"><?= icon('search', 34) ?></div>Menu tidak ditemukan.</div>
  </div>

  <!-- ============ Keranjang ============ -->
  <div class="card cart-panel" id="cart-panel">
    <div class="card-head">
      <h3>Pesanan</h3>
      <button class="btn btn-ghost btn-sm" id="hold-cart" style="margin-left:auto" type="button" title="Simpan sementara, layani pelanggan lain">Tahan</button>
      <button class="btn btn-ghost btn-sm" id="clear-cart" type="button">Kosongkan</button>
      <button class="btn btn-ghost btn-sm cart-close" id="cart-close" type="button" aria-label="Tutup">&times;</button>
    </div>

    <div class="card-body" style="padding-bottom:12px">
      <div class="form-row" style="gap:10px">
        <div>
          <label class="field-label">Tipe Pesanan</label>
          <select class="select" id="tipe">
            <option value="dine_in">Dine In</option>
            <option value="take_away">Take Away</option>
            <option value="delivery">Delivery</option>
          </select>
        </div>
        <div>
          <label class="field-label">Meja</label>
          <select class="select" id="table_id">
            <option value="">- Tanpa Meja -</option>
            <?php foreach ($mejaList as $m): ?>
              <option value="<?= (int)$m['id'] ?>" data-status="<?= e($m['status']) ?>">
                <?= e($m['nama']) ?> (<?= e($m['area']) ?><?= $m['status'] !== 'kosong' ? ' - ' . e($m['status']) : '' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group" style="margin:10px 0 0">
        <label class="field-label">Nama Pelanggan (opsional)</label>
        <input class="input" type="text" id="nama_pelanggan" placeholder="mis. Budi / Meja Andi">
      </div>
    </div>

    <div class="cart-items" id="cart-items"></div>

    <div class="card-body" style="border-top:1px solid var(--border-2);padding-top:14px">
      <div class="form-row" style="gap:10px;margin-bottom:10px;grid-template-columns:1fr 1.4fr">
        <div>
          <label class="field-label">Diskon (%)</label>
          <input class="input mono" type="number" id="diskon" min="0" max="100" step="0.5" value="0" inputmode="decimal">
        </div>
        <div class="hint" style="align-self:end;padding-bottom:8px">
          <?php if (is_role('kasir')): ?>Batas kasir <?= $fmtPersen($maxDiskon) ?>%. Lebih dari itu perlu PIN supervisor.<?php else: ?>Diskon besar wajib disertai alasan.<?php endif; ?>
        </div>
      </div>

      <div class="cart-total-row"><span>Subtotal</span><span class="v" id="t-subtotal">Rp 0</span></div>
      <div class="cart-total-row"><span>Diskon</span><span class="v" id="t-diskon">Rp 0</span></div>
      <?php if ($servicePersen > 0): ?>
      <div class="cart-total-row"><span>Service <?= $fmtPersen($servicePersen) ?>%</span><span class="v" id="t-service">Rp 0</span></div>
      <?php endif; ?>
      <div class="cart-total-row"><span>Pajak <?= $fmtPersen($pajakPersen) ?>%</span><span class="v" id="t-pajak">Rp 0</span></div>
      <div class="cart-total-row grand"><span>Total</span><span class="v" id="t-total">Rp 0</span></div>

      <?php if ($bill): ?>
      <button class="btn btn-primary btn-block btn-lg mt-2" id="btn-tambah-bill" type="button" disabled>Tambahkan ke Bill</button>
      <button class="hidden" id="btn-bayar" type="button" disabled></button>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:1fr 1.6fr;gap:8px" class="mt-2">
        <button class="btn btn-light btn-lg" id="btn-simpan-bill" type="button" disabled title="Simpan pesanan, bayar nanti (open bill)">Simpan Bill</button>
        <button class="btn btn-primary btn-lg" id="btn-bayar" type="button" disabled>Bayar Sekarang</button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Bar keranjang untuk layar kecil -->
<button class="cart-fab" id="cart-fab" type="button">
  <span class="cf-ico"><?= icon('cart', 20) ?><span class="cf-count" id="cf-count">0</span></span>
  <span class="cf-label">Lihat Pesanan</span>
  <span class="cf-total mono" id="cf-total">Rp 0</span>
</button>
<div class="cart-overlay" id="cart-overlay"></div>

<!-- ============ Modal Varian ============ -->
<div class="modal-backdrop" id="modal-varian">
  <div class="modal">
    <div class="modal-head">
      <h3 id="v-nama">Pilih Varian</h3>
      <button class="modal-close" data-modal-close type="button">&times;</button>
    </div>
    <div class="modal-body">
      <div id="v-body"></div>
      <div class="form-group" style="margin-bottom:0">
        <label class="field-label">Catatan</label>
        <input class="input" type="text" id="v-catatan" placeholder="mis. less sugar, tanpa es">
      </div>
    </div>
    <div class="modal-foot">
      <div style="flex:1">
        <div class="muted" style="font-size:11.5px">Subtotal</div>
        <div class="mono" style="font-weight:800;font-size:17px" id="v-subtotal">Rp 0</div>
      </div>
      <button class="btn btn-light" data-modal-close type="button">Batal</button>
      <button class="btn btn-primary" id="v-tambah" type="button">Tambah</button>
    </div>
  </div>
</div>

<!-- ============ Modal Pesanan Ditahan ============ -->
<div class="modal-backdrop" id="modal-held">
  <div class="modal">
    <div class="modal-head">
      <h3>Pesanan Ditahan</h3>
      <button class="modal-close" data-modal-close type="button">&times;</button>
    </div>
    <div class="modal-body" id="held-list"></div>
  </div>
</div>

<!-- ============ Modal Pembayaran ============ -->
<div class="modal-backdrop" id="modal-bayar">
  <div class="modal">
    <div class="modal-head">
      <h3>Pembayaran</h3>
      <button class="modal-close" data-modal-close type="button">&times;</button>
    </div>
    <div class="modal-body">
      <div style="text-align:center;padding:6px 0 16px">
        <div class="muted" style="font-size:12px;letter-spacing:.06em;text-transform:uppercase">Total Tagihan</div>
        <div class="mono" style="font-size:34px;font-weight:800" id="pay-total">Rp 0</div>
      </div>

      <div class="form-group">
        <label class="field-label">Metode Pembayaran</label>
        <select class="select" id="metode">
          <option value="cash">Tunai (Cash)</option>
          <option value="qris">QRIS</option>
          <option value="debit">Kartu Debit</option>
          <option value="kredit">Kartu Kredit</option>
          <option value="ewallet">E-Wallet</option>
          <option value="transfer">Transfer Bank</option>
        </select>
      </div>

      <?php if ($setting['qris_image']): ?>
      <div id="qris-box" class="text-center hidden" style="margin-bottom:14px">
        <img src="<?= e(UPLOAD_URL . $setting['qris_image']) ?>" alt="QRIS" style="max-width:240px;width:100%;border-radius:12px;border:1px solid var(--border)">
        <div class="hint">Minta pelanggan memindai, pastikan nominal sesuai total, lalu proses setelah pembayaran masuk.</div>
      </div>
      <?php endif; ?>
      <div class="form-group" id="pay-input-wrap">
        <label class="field-label">Jumlah Bayar</label>
        <input class="input mono" type="number" id="pay-input" min="0" step="500" value="0" style="font-size:18px;font-weight:700">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px" id="quick-cash">
          <button class="btn btn-light btn-sm" data-cash="exact" type="button">Uang Pas</button>
          <button class="btn btn-light btn-sm" data-cash="50000" type="button">50.000</button>
          <button class="btn btn-light btn-sm" data-cash="100000" type="button">100.000</button>
          <button class="btn btn-light btn-sm" data-cash="150000" type="button">150.000</button>
          <button class="btn btn-light btn-sm" data-cash="200000" type="button">200.000</button>
        </div>
      </div>

      <div id="diskon-auth" class="card card-pad hidden" style="background:var(--surface-2);margin-bottom:14px">
        <div style="font-weight:700;margin-bottom:8px">Otorisasi diskon</div>
        <div class="form-group">
          <label class="field-label">Alasan diskon</label>
          <input class="input" type="text" id="diskon-alasan" maxlength="150" placeholder="mis. kompensasi keluhan, member, promo">
        </div>
        <div class="form-group hidden" id="pin-wrap" style="margin-bottom:0">
          <label class="field-label">PIN supervisor</label>
          <input class="input mono" type="password" id="supervisor-pin" inputmode="numeric" maxlength="8" autocomplete="off" placeholder="PIN admin / pemilik">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:0">
        <label class="field-label">Catatan Pesanan</label>
        <input class="input" type="text" id="pay-catatan" placeholder="opsional" maxlength="255">
      </div>

      <div class="cart-total-row grand" style="border-top:1px solid var(--border)">
        <span>Kembalian</span><span class="v" id="pay-kembalian">Rp 0</span>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-light" data-modal-close type="button">Batal</button>
      <button class="btn btn-primary btn-lg" id="btn-proses" type="button">Proses &amp; Cetak Struk</button>
    </div>
  </div>
</div>

<script>
window.PRODUK = <?= json_encode($produkJs, JSON_UNESCAPED_UNICODE) ?>;
window.BASE = <?= json_encode(BASE_URL) ?>;
window.CSRF = <?= json_encode(csrf_token()) ?>;
window.POS_CFG = <?= json_encode([
    'cartKey'   => 'kasirku_cart_' . (int)current_user()['id'] . '_' . (int)active_outlet_id(),
    'pajak'     => $pajakPersen,
    'service'   => $servicePersen,
    'maxDiskon' => $maxDiskon,
    'isKasir'   => is_role('kasir'),
    'batasAlasan' => (float)$setting['max_diskon_kasir'],
    'billId'    => $bill ? (int)$bill['id'] : 0,
    'hasShift'  => $hasShift,
]) ?>;
</script>
<script src="<?= e(BASE_URL) ?>assets/js/pos.js?v=<?= e(APP_VERSION) ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
