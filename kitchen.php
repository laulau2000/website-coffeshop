<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_outlet();
$page_title = 'Layar Dapur / Bar';
$page_sub   = 'Pesanan masuk otomatis, ketuk item untuk ubah status';
$active     = 'kitchen.php';
include __DIR__ . '/includes/header.php';
?>
<div class="kds-toolbar no-print">
  <div class="cat-chips" style="margin:0">
    <button class="cat-chip active" data-st="" type="button">Semua</button>
    <button class="cat-chip" data-st="dapur" type="button">Dapur</button>
    <button class="cat-chip" data-st="bar" type="button">Bar</button>
  </div>
  <label class="check" style="margin:0 0 0 auto"><input type="checkbox" id="kds-sound" checked> Bunyi pesanan baru</label>
  <span class="badge badge-info" id="kds-time">-</span>
</div>
<div class="kds-legend hint">Ketuk item: <span class="kds-pill baru">Baru</span> &rarr; <span class="kds-pill dimasak">Dimasak</span> &rarr; <span class="kds-pill siap">Siap</span> &rarr; diantar (hilang dari layar).</div>
<div class="kds-grid" id="kds-grid"></div>
<div class="empty-state hidden" id="kds-empty"><div class="es-ico"><?= icon('check', 34) ?></div>Tidak ada pesanan yang menunggu.</div>
<script>
(function () {
  const BASE = <?= json_encode(BASE_URL) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
  let st = '', known = null, busy = false;
  const label = { dine_in: 'Dine In', take_away: 'Take Away', delivery: 'Delivery' };
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function beep() {
    if (!document.getElementById('kds-sound').checked) return;
    try { const a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(); o.frequency.value = 880; o.connect(a.destination); o.start(); setTimeout(() => { o.stop(); a.close(); }, 250); } catch (e) {}
  }
  async function load() {
    if (busy) return;
    try {
      const r = await fetch(BASE + 'api/kitchen.php' + (st ? '?stasiun=' + st : ''), { headers: { Accept: 'application/json' } });
      if (r.status === 401) { location = BASE + 'login.php'; return; }
      const d = await r.json();
      if (!d.ok) return;
      document.getElementById('kds-time').textContent = 'Update ' + d.server_time;
      const ids = new Set(d.tickets.flatMap(t => t.items.map(i => i.id)));
      if (known && [...ids].some(i => !known.has(i))) beep();
      known = ids;
      document.getElementById('kds-empty').classList.toggle('hidden', d.tickets.length > 0);
      document.getElementById('kds-grid').innerHTML = d.tickets.map(t => `
        <div class="kds-card ${t.menit >= 20 ? 'late' : (t.menit >= 10 ? 'warn' : '')}">
          <div class="kds-head">
            <div><strong>${esc(t.meja ? (/^meja\b/i.test(t.meja) ? t.meja : 'Meja ' + t.meja) : (t.pelanggan || label[t.tipe]))}</strong>
              <div class="muted" style="font-size:12px">${esc(t.kode)}${t.batch > 1 ? ' &middot; tambahan #' + t.batch : ''} &middot; ${esc(label[t.tipe])}</div></div>
            <span class="kds-timer">${t.menit}m</span>
          </div>
          ${t.items.map(i => `<button type="button" class="kds-item ${i.status}" data-id="${i.id}">
              <span class="kds-qty">${i.qty}x</span>
              <span style="flex:1;text-align:left"><strong>${esc(i.nama)}</strong>${i.varian ? `<br><small>${esc(i.varian)}</small>` : ''}${i.catatan ? `<br><small class="kds-note">* ${esc(i.catatan)}</small>` : ''}</span>
              <span class="kds-pill ${i.status}">${i.status}</span></button>`).join('')}
          <button type="button" class="btn btn-light btn-sm btn-block kds-all" data-ids="${t.items.map(i => i.id).join(',')}">Semua siap diantar</button>
        </div>`).join('');
    } catch (e) { document.getElementById('kds-time').textContent = 'Offline - mencoba lagi'; }
  }
  async function update(ids, status) {
    busy = true;
    try {
      await fetch(BASE + 'api/kitchen.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ _csrf: CSRF, item_ids: ids, status: status || '' }) });
    } finally { busy = false; load(); }
  }
  document.addEventListener('click', ev => {
    const it = ev.target.closest('.kds-item'); if (it) { update([Number(it.dataset.id)]); return; }
    const all = ev.target.closest('.kds-all'); if (all) { update(all.dataset.ids.split(',').map(Number), 'diantar'); return; }
    const chip = ev.target.closest('.cat-chip[data-st]');
    if (chip) { document.querySelectorAll('.cat-chip[data-st]').forEach(c => c.classList.remove('active')); chip.classList.add('active'); st = chip.dataset.st; known = null; load(); }
  });
  load(); setInterval(load, 5000);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
