<?php
require_once __DIR__ . '/includes/orders.php';
require_role('superadmin', 'admin', 'kasir');
$outletId = require_outlet();
$me = current_user();
$id = (int)get('id', 0);
$bill = fetchOne('SELECT o.*, rt.nama meja FROM orders o LEFT JOIN resto_tables rt ON rt.id = o.table_id WHERE o.id = ? AND o.outlet_id = ?', [$id, $outletId]);
if (!$bill) { set_flash('danger', 'Bill tidak ditemukan.'); redirect('bills.php'); }
if ($bill['lunas'] || $bill['status'] === 'batal') redirect('order-detail.php?id=' . $id);

$items = fetchAll('SELECT * FROM order_items WHERE order_id = ? ORDER BY batch, id', [$id]);
$set = outlet_settings($outletId);
$meja = fetchAll('SELECT id, nama, area, status FROM resto_tables WHERE outlet_id = ? ORDER BY area, nama', [$outletId]);
$lain = fetchAll("SELECT o.id, o.kode, o.nama_pelanggan, rt.nama meja FROM orders o LEFT JOIN resto_tables rt ON rt.id = o.table_id
                  WHERE o.outlet_id = ? AND o.lunas = 0 AND o.status <> 'batal' AND o.id <> ? ORDER BY o.id", [$outletId, $id]);
$shift = current_shift((int)$me['id'], $outletId);
$maxBatch = $items ? max(array_column($items, 'batch')) : 1;
$fmtPersen = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');

$page_title = 'Bill ' . ($bill['meja'] ? meja_label($bill['meja']) : ($bill['nama_pelanggan'] ?: $bill['kode']));
$page_sub   = $bill['kode'] . ' · dibuka ' . tanggal_indo($bill['created_at']);
$active     = 'bills.php';
include __DIR__ . '/includes/header.php';
?>
<div class="no-print" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <a class="btn btn-light" href="<?= e(BASE_URL) ?>bills.php">&larr; Open Bill</a>
  <a class="btn btn-accent" href="<?= e(BASE_URL) ?>pos.php?bill=<?= $id ?>">+ Tambah Pesanan</a>
  <a class="btn btn-light" href="<?= e(BASE_URL) ?>order-detail.php?id=<?= $id ?>&tiket=1&batch=<?= (int)$maxBatch ?>" target="_blank"><?= icon('printer', 16) ?> Tiket Dapur</a>
  <a class="btn btn-light" href="<?= e(BASE_URL) ?>order-detail.php?id=<?= $id ?>" target="_blank"><?= icon('printer', 16) ?> Cetak Tagihan</a>
</div>
<?php if (!$shift): ?>
  <div class="alert alert-warning">Shift Anda belum dibuka. <a href="<?= e(BASE_URL) ?>shift.php"><strong>Buka shift</strong></a> untuk menerima pembayaran.</div>
<?php endif; ?>

<div class="grid" style="grid-template-columns: 1.3fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head"><h3>Item Pesanan</h3><span class="hint" style="margin-left:auto">Centang item untuk bayar sebagian (split bill)</span></div>
    <div class="table-wrap">
      <table class="data" id="bill-items">
        <thead><tr><th style="width:34px"><input type="checkbox" id="chk-all" checked aria-label="Pilih semua"></th><th>Menu</th><th>Dapur</th><th class="text-center">Qty bayar</th><th class="text-right">Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr data-id="<?= (int)$it['id'] ?>" data-harga="<?= (float)$it['harga'] ?>" data-qty="<?= (int)$it['qty'] ?>">
            <td><input type="checkbox" class="chk-item" checked></td>
            <td><strong><?= e($it['nama_produk']) ?></strong> <span class="muted">x<?= (int)$it['qty'] ?></span>
              <?php if ($it['varian']): ?><div class="muted" style="font-size:12px"><?= e($it['varian']) ?></div><?php endif; ?>
              <?php if ($it['catatan']): ?><div class="muted" style="font-size:12px">* <?= e($it['catatan']) ?></div><?php endif; ?>
              <?php if ($it['batch'] > 1): ?><span class="badge" style="font-size:10px">tambahan #<?= (int)$it['batch'] ?></span><?php endif; ?></td>
            <td><span class="kds-pill <?= e($it['status_dapur']) ?>"><?= e($it['status_dapur']) ?></span></td>
            <td class="text-center"><input class="input mono qty-bayar" type="number" min="1" max="<?= (int)$it['qty'] ?>" value="<?= (int)$it['qty'] ?>" style="width:70px;padding:6px"></td>
            <td class="text-right mono"><?= rupiah($it['subtotal']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border-2)">
      <div class="form-row" style="gap:10px">
        <div>
          <label class="field-label">Pindah meja</label>
          <div style="display:flex;gap:8px">
            <select class="select" id="pindah-meja">
              <?php foreach ($meja as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === (int)$bill['table_id'] ? ' selected' : '' ?>><?= e($m['nama']) ?> (<?= e($m['area']) ?><?= $m['status'] !== 'kosong' ? ' - ' . e($m['status']) : '' ?>)</option><?php endforeach; ?>
            </select>
            <button class="btn btn-light" type="button" id="btn-pindah">Pindah</button>
          </div>
        </div>
        <div>
          <label class="field-label">Gabung ke bill lain</label>
          <div style="display:flex;gap:8px">
            <select class="select" id="gabung-target"<?= $lain ? '' : ' disabled' ?>>
              <?php if (!$lain): ?><option>Tidak ada bill lain</option><?php endif; ?>
              <?php foreach ($lain as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e(($l['meja'] ? meja_label($l['meja']) : ($l['nama_pelanggan'] ?: '-')) . ' - ' . $l['kode']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-light" type="button" id="btn-gabung"<?= $lain ? '' : ' disabled' ?>>Gabung</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Pembayaran</h3></div>
    <div class="card-body">
      <div class="cart-total-row"><span>Subtotal dipilih</span><span class="v" id="b-sub">Rp 0</span></div>
      <div class="cart-total-row"><span>Diskon</span><span class="v" id="b-disk">Rp 0</span></div>
      <?php if ((float)$set['service_persen'] > 0): ?><div class="cart-total-row"><span>Service <?= $fmtPersen($set['service_persen']) ?>%</span><span class="v" id="b-serv">Rp 0</span></div><?php endif; ?>
      <div class="cart-total-row"><span>Pajak <?= $fmtPersen($set['pajak_persen']) ?>%</span><span class="v" id="b-pajak">Rp 0</span></div>
      <div class="cart-total-row grand"><span>Total</span><span class="v" id="b-total">Rp 0</span></div>
      <div class="form-row mt-2" style="gap:10px">
        <div><label class="field-label">Diskon (%)</label><input class="input mono" type="number" id="b-diskon" min="0" max="100" step="0.5" value="0"></div>
        <div><label class="field-label">Metode</label>
          <select class="select" id="b-metode"><option value="cash">Tunai</option><option value="qris">QRIS</option><option value="debit">Debit</option><option value="kredit">Kredit</option><option value="ewallet">E-Wallet</option><option value="transfer">Transfer</option></select></div>
      </div>
      <?php if ($set['qris_image']): ?><div id="b-qris" class="text-center hidden" style="margin:10px 0"><img src="<?= e(UPLOAD_URL . $set['qris_image']) ?>" alt="QRIS" style="max-width:220px;width:100%;border-radius:10px"></div><?php endif; ?>
      <div class="form-group" id="b-bayar-wrap"><label class="field-label">Jumlah bayar</label><input class="input mono" type="number" id="b-bayar" min="0" step="500" style="font-size:18px;font-weight:700"></div>
      <div id="b-auth" class="hidden">
        <div class="form-group"><label class="field-label">Alasan diskon</label><input class="input" type="text" id="b-alasan" maxlength="150"></div>
        <div class="form-group hidden" id="b-pin-wrap"><label class="field-label">PIN supervisor</label><input class="input mono" type="password" id="b-pin" inputmode="numeric" maxlength="8" autocomplete="off"></div>
      </div>
      <div class="cart-total-row grand" style="border-top:0"><span>Kembalian</span><span class="v" id="b-kembali">Rp 0</span></div>
      <button class="btn btn-primary btn-block btn-lg mt-2" type="button" id="btn-bayar-bill"<?= $shift ? '' : ' disabled' ?>>Bayar</button>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>, ID = <?= $id ?>;
  const CFG = <?= json_encode(['pajak' => (float)$set['pajak_persen'], 'service' => (float)$set['service_persen'],
    'max' => is_role('kasir') ? (float)$set['max_diskon_kasir'] : 100, 'batas' => (float)$set['max_diskon_kasir']]) ?>;
  const $ = s => document.querySelector(s), R = KasirKu.rupiah;
  const ref = (crypto.randomUUID ? crypto.randomUUID() : 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
  function rows() { return [...document.querySelectorAll('#bill-items tbody tr')]; }
  function hitung() {
    let sub = 0;
    rows().forEach(r => { if (r.querySelector('.chk-item').checked) sub += Number(r.dataset.harga) * Math.min(Number(r.dataset.qty), Math.max(1, Number(r.querySelector('.qty-bayar').value) || 1)); });
    const dp = Math.min(100, Math.max(0, Number($('#b-diskon').value) || 0));
    const disk = Math.round(sub * dp / 100), dasar = sub - disk, serv = Math.round(dasar * CFG.service / 100), pajak = Math.round((dasar + serv) * CFG.pajak / 100);
    const total = dasar + serv + pajak;
    $('#b-sub').textContent = R(sub); $('#b-disk').textContent = '- ' + R(disk); if ($('#b-serv')) $('#b-serv').textContent = R(serv);
    $('#b-pajak').textContent = R(pajak); $('#b-total').textContent = R(total);
    const cash = $('#b-metode').value === 'cash';
    $('#b-bayar-wrap').classList.toggle('hidden', !cash);
    if ($('#b-qris')) $('#b-qris').classList.toggle('hidden', $('#b-metode').value !== 'qris');
    if (!cash || !$('#b-bayar').dataset.touched) $('#b-bayar').value = total;
    $('#b-kembali').textContent = R(Math.max(0, (Number($('#b-bayar').value) || 0) - total));
    $('#b-auth').classList.toggle('hidden', !(dp > CFG.batas || dp > CFG.max));
    $('#b-pin-wrap').classList.toggle('hidden', !(dp > CFG.max));
    return { sub, dp, total };
  }
  async function kirim(body) {
    const r = await fetch(BASE + 'api/bill.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(Object.assign({ _csrf: CSRF, order_id: ID }, body)) });
    const d = await r.json().catch(() => ({ ok: false, message: 'Respon server tidak valid.' }));
    if (d.need_pin) { $('#b-auth').classList.remove('hidden'); $('#b-pin-wrap').classList.remove('hidden'); }
    if (!d.ok) throw new Error(d.message || 'Gagal.');
    return d;
  }
  document.addEventListener('input', ev => { if (ev.target.id === 'b-bayar') ev.target.dataset.touched = 1; hitung(); });
  document.addEventListener('change', ev => {
    if (ev.target.id === 'chk-all') rows().forEach(r => r.querySelector('.chk-item').checked = ev.target.checked);
    hitung();
  });
  $('#btn-bayar-bill').addEventListener('click', async function () {
    const t = hitung();
    if (t.sub <= 0) { KasirKu.toast('Pilih minimal satu item.', 'warning'); return; }
    const split = rows().filter(r => r.querySelector('.chk-item').checked).map(r => ({ item_id: Number(r.dataset.id), qty: Math.min(Number(r.dataset.qty), Math.max(1, Number(r.querySelector('.qty-bayar').value) || 1)) }));
    this.disabled = true; this.textContent = 'Memproses...';
    try {
      const d = await kirim({ action: 'bayar', client_ref: ref, split, diskon_persen: t.dp, diskon_alasan: $('#b-alasan').value, supervisor_pin: $('#b-pin').value,
        metode_bayar: $('#b-metode').value, bayar: Number($('#b-bayar').value) || 0 });
      location = BASE + 'order-detail.php?id=' + d.id + '&print=1' + (d.sisa_bill ? '&next_bill=' + d.sisa_bill : '');
    } catch (e) { KasirKu.toast(e.message, 'danger', 4500); this.disabled = false; this.textContent = 'Bayar'; }
  });
  $('#btn-pindah').addEventListener('click', async () => {
    try { await kirim({ action: 'pindah', table_id: Number($('#pindah-meja').value) }); location.reload(); } catch (e) { KasirKu.toast(e.message, 'danger'); }
  });
  $('#btn-gabung').addEventListener('click', async () => {
    if (!confirm('Gabungkan semua item bill ini ke bill tujuan?')) return;
    try { const d = await kirim({ action: 'gabung', target_id: Number($('#gabung-target').value) }); location = BASE + 'bill.php?id=' + d.id; } catch (e) { KasirKu.toast(e.message, 'danger'); }
  });
  hitung();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
