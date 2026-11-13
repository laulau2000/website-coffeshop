/* =====================================================================
   KasirKu POS - Logika halaman kasir
   Harga final selalu dihitung ulang oleh server (api/checkout.php).
   ===================================================================== */
(function () {
  'use strict';

  const PRODUK = window.PRODUK || [];
  const BASE = window.BASE || '/';
  const CFG = Object.assign({ cartKey: 'kasirku_cart', pajak: 0, service: 0, maxDiskon: 100, isKasir: false, batasAlasan: 10 }, window.POS_CFG || {});
  if (CFG.billId) CFG.cartKey += '_bill' + CFG.billId;
  const HOLD_KEY = CFG.cartKey.replace(/_bill\d+$/, '') + '_held';
  const $ = (s) => document.querySelector(s);

  let cart = [];
  let filterKat = 'all';
  let onlyFav = false;
  let keyword = '';
  let pendingVarian = null;
  let clientRef = null;

  /* ---------------- Persistensi keranjang (per user & cabang) ---------------- */
  function saveCart() {
    try { localStorage.setItem(CFG.cartKey, JSON.stringify(cart)); } catch (e) {}
  }
  function loadCart() {
    try { localStorage.removeItem('kasirku_cart'); } catch (e) {}
    try { cart = JSON.parse(localStorage.getItem(CFG.cartKey) || '[]'); } catch (e) { cart = []; }
    // Buang item produk yang sudah tidak tersedia & sinkronkan harga terbaru
    cart = (Array.isArray(cart) ? cart : []).filter(c => {
      const p = PRODUK.find(x => x.id === c.product_id);
      if (!p || !Array.isArray(c.pilihan)) return false;
      c.harga = hargaVarian(p, c.pilihan);
      c.nama = p.nama;
      return true;
    });
  }
  function getHeld() {
    try { return JSON.parse(localStorage.getItem(HOLD_KEY) || '[]'); } catch (e) { return []; }
  }
  function setHeld(list) {
    try { localStorage.setItem(HOLD_KEY, JSON.stringify(list)); } catch (e) {}
    renderHeldCount();
  }
  function renderHeldCount() {
    const n = getHeld().length;
    const el = $('#held-count');
    if (el) { el.textContent = n; el.classList.toggle('hidden', n === 0); }
  }

  /* ---------------- Util ---------------- */
  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function hargaVarian(p, pilihan) {
    let h = Number(p.harga) || 0;
    (p.varian || []).forEach((g, gi) => {
      const o = (g.opsi || [])[pilihan[gi] || 0];
      if (o) h += Math.max(0, Number(o.tambahan) || 0);
    });
    return h;
  }
  function qtyDiKeranjang(pid) {
    return cart.filter(c => c.product_id === pid).reduce((s, c) => s + c.qty, 0);
  }
  function stokCukup(p, tambah) {
    if (p.stok === null || p.stok === undefined) return true;
    return qtyDiKeranjang(p.id) + (tambah || 1) <= p.stok;
  }
  function newRef() {
    return (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
      : 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
  }

  /* ---------------- Render katalog ---------------- */
  const PLACEHOLDER = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h13v5a6 6 0 0 1-6 6h-1a6 6 0 0 1-6-6V8z"/><path d="M17 10h1.5a2.5 2.5 0 0 1 0 5H17"/><path d="M8 2v3M12 2v3"/></svg>';

  function renderProduk() {
    const grid = $('#prod-grid');
    const list = PRODUK.filter(p => {
      if (filterKat !== 'all' && String(p.kat_id) !== String(filterKat)) return false;
      if (onlyFav && !p.favorit) return false;
      if (keyword && !(p.nama.toLowerCase().includes(keyword) || p.kategori.toLowerCase().includes(keyword))) return false;
      return true;
    });

    grid.innerHTML = list.map(p => {
      const habis = p.stok !== null && p.stok <= 0;
      const sedikit = p.stok !== null && p.stok > 0 && p.stok <= 5;
      const thumb = p.gambar ? `<img src="${esc(p.gambar)}" alt="" loading="lazy">` : PLACEHOLDER;
      const info = habis ? 'Stok habis' : (sedikit ? `Sisa ${p.stok}` : esc(p.kategori));
      return `<button type="button" class="prod-card${habis ? ' out' : ''}" data-id="${p.id}"${habis ? ' aria-disabled="true"' : ''}>
        ${p.favorit ? '<span class="fav">FAV</span>' : ''}
        <div class="thumb">${thumb}</div>
        <div class="p-body">
          <div class="p-name">${esc(p.nama)}</div>
          <div class="p-stock${sedikit ? ' low' : ''}">${info}</div>
          <div class="p-price">${KasirKu.rupiah(p.harga)}</div>
        </div>
      </button>`;
    }).join('');

    $('#prod-empty').classList.toggle('hidden', list.length > 0);
  }

  /* ---------------- Varian ---------------- */
  function openVarian(produk) {
    pendingVarian = produk;
    $('#v-nama').textContent = produk.nama;
    const body = $('#v-body');
    body.innerHTML = (produk.varian || []).map((grp, gi) =>
      `<div class="form-group" data-gi="${gi}">
        <label class="field-label">${esc(grp.nama)}</label>
        <select class="select v-opt">
          ${grp.opsi.map((o, oi) => `<option value="${oi}"${oi === 0 ? ' selected' : ''}>${esc(o.label)}${o.tambahan > 0 ? ' (+' + KasirKu.rupiah(o.tambahan) + ')' : ''}</option>`).join('')}
        </select>
      </div>`
    ).join('');
    $('#v-catatan').value = '';
    body.querySelectorAll('.v-opt').forEach(sel => sel.addEventListener('change', hitungVarian));
    hitungVarian();
    KasirKu.openModal('modal-varian');
  }

  function pilihanVarian() {
    const pil = [];
    document.querySelectorAll('#v-body .form-group').forEach(g => {
      pil[Number(g.dataset.gi)] = Number(g.querySelector('.v-opt').value);
    });
    return pil;
  }

  function hitungVarian() {
    if (!pendingVarian) return 0;
    const total = hargaVarian(pendingVarian, pilihanVarian());
    $('#v-subtotal').textContent = KasirKu.rupiah(total);
    return total;
  }

  function tambahDariVarian() {
    if (!pendingVarian) return;
    addToCart(pendingVarian, pilihanVarian(), $('#v-catatan').value.trim());
    KasirKu.closeModal('modal-varian');
    pendingVarian = null;
  }

  /* ---------------- Keranjang ---------------- */
  function addToCart(produk, pilihan, catatan) {
    if (!stokCukup(produk, 1)) { KasirKu.toast('Stok ' + produk.nama + ' tidak cukup.', 'warning'); return; }
    const labels = (produk.varian || []).map((g, gi) => (g.opsi[pilihan[gi] || 0] || {}).label).filter(Boolean);
    const key = produk.id + '::' + pilihan.join('-') + '::' + (catatan || '');
    const ada = cart.find(c => c.key === key);
    if (ada) {
      ada.qty++;
    } else {
      cart.push({
        key,
        product_id: produk.id,
        nama: produk.nama,
        harga: hargaVarian(produk, pilihan),
        qty: 1,
        pilihan: pilihan,
        varianText: labels.join(', '),
        catatan: catatan || ''
      });
    }
    clientRef = null;
    renderCart();
    KasirKu.toast(produk.nama + ' ditambahkan', 'success', 1200);
  }

  function renderCart() {
    const wrap = $('#cart-items');
    if (!cart.length) {
      wrap.innerHTML = '<div class="empty-state"><div class="es-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L21 8H6"/></svg></div>Keranjang masih kosong.<br><span style="font-size:12px">Ketuk menu untuk menambahkan.</span></div>';
    } else {
      wrap.innerHTML = cart.map((c, i) => `
        <div class="cart-item" data-i="${i}">
          <div class="ci-main">
            <div class="ci-name">${esc(c.nama)}</div>
            ${c.varianText ? `<div class="ci-var">${esc(c.varianText)}</div>` : ''}
            ${c.catatan ? `<div class="ci-var">Catatan: ${esc(c.catatan)}</div>` : ''}
            <div class="ci-price">${KasirKu.rupiah(c.harga)}</div>
            <div class="qty-ctrl">
              <button type="button" data-act="minus" aria-label="Kurangi">&minus;</button>
              <span class="q">${c.qty}</span>
              <button type="button" data-act="plus" aria-label="Tambah">+</button>
            </div>
          </div>
          <div style="text-align:right;display:flex;flex-direction:column;justify-content:space-between">
            <button class="btn btn-ghost btn-sm" data-act="del" type="button" title="Hapus" aria-label="Hapus">&times;</button>
            <div class="mono" style="font-weight:700">${KasirKu.rupiah(c.harga * c.qty)}</div>
          </div>
        </div>`).join('');
    }
    hitungTotal();
    saveCart();
  }

  /** Sama dengan hitung_total() di server: diskon -> service -> pajak, dibulatkan per rupiah. */
  function hitungTotal() {
    const subtotal = cart.reduce((s, c) => s + c.harga * c.qty, 0);
    const el = $('#diskon');
    let dp = Math.min(100, Math.max(0, Number(el.value) || 0));
    if (String(dp) !== el.value && el.value !== '') el.value = dp;
    const diskonNilai = Math.round(subtotal * dp / 100);
    const dasar = subtotal - diskonNilai;
    const serviceNilai = Math.round(dasar * CFG.service / 100);
    const pajakNilai = Math.round((dasar + serviceNilai) * CFG.pajak / 100);
    const total = dasar + serviceNilai + pajakNilai;
    const qty = cart.reduce((s, c) => s + c.qty, 0);

    $('#t-subtotal').textContent = KasirKu.rupiah(subtotal);
    $('#t-diskon').textContent = '- ' + KasirKu.rupiah(diskonNilai);
    if ($('#t-service')) $('#t-service').textContent = KasirKu.rupiah(serviceNilai);
    $('#t-pajak').textContent = KasirKu.rupiah(pajakNilai);
    $('#t-total').textContent = KasirKu.rupiah(total);
    $('#btn-bayar').disabled = cart.length === 0;
    if ($('#btn-simpan-bill')) $('#btn-simpan-bill').disabled = cart.length === 0;
    if ($('#btn-tambah-bill')) $('#btn-tambah-bill').disabled = cart.length === 0;
    $('#cf-total').textContent = KasirKu.rupiah(total);
    $('#cf-count').textContent = qty;
    $('#cart-fab').classList.toggle('has-items', qty > 0);
    return { subtotal, dp, diskonNilai, serviceNilai, pajakNilai, total };
  }

  /* ---------------- Pembayaran ---------------- */
  function updateDiskonAuth(dp) {
    const butuhAlasan = dp > CFG.batasAlasan;
    const butuhPin = CFG.isKasir && dp > CFG.maxDiskon;
    $('#diskon-auth').classList.toggle('hidden', !butuhAlasan && !butuhPin);
    $('#pin-wrap').classList.toggle('hidden', !butuhPin);
  }

  function bukaBayar() {
    const t = hitungTotal();
    if (!cart.length) return;
    if (!clientRef) clientRef = newRef();
    $('#pay-total').textContent = KasirKu.rupiah(t.total);
    $('#pay-input').value = t.total;
    $('#pay-catatan').value = '';
    $('#supervisor-pin').value = '';
    updateDiskonAuth(t.dp);
    toggleMetode();
    hitungKembalian();
    KasirKu.openModal('modal-bayar');
  }

  function toggleMetode() {
    const cash = $('#metode').value === 'cash';
    $('#quick-cash').style.display = cash ? 'flex' : 'none';
    if ($('#qris-box')) $('#qris-box').classList.toggle('hidden', $('#metode').value !== 'qris');
    $('#pay-input').readOnly = !cash;
    if (!cash) $('#pay-input').value = hitungTotal().total;
    hitungKembalian();
  }

  function hitungKembalian() {
    const t = hitungTotal().total;
    const bayar = Number($('#pay-input').value) || 0;
    const kembali = bayar - t;
    const el = $('#pay-kembalian');
    el.textContent = KasirKu.rupiah(Math.max(0, kembali));
    el.style.color = kembali < 0 ? 'var(--danger)' : 'var(--text)';
  }

  async function prosesBayar() {
    const t = hitungTotal();
    const bayar = Number($('#pay-input').value) || 0;
    if ($('#metode').value === 'cash' && bayar < t.total) {
      KasirKu.toast('Jumlah bayar kurang dari total tagihan.', 'danger');
      return;
    }
    if (t.dp > CFG.batasAlasan && !$('#diskon-alasan').value.trim()) {
      KasirKu.toast('Isi alasan diskon terlebih dahulu.', 'warning');
      $('#diskon-alasan').focus();
      return;
    }
    const btn = $('#btn-proses');
    btn.disabled = true;
    btn.textContent = 'Memproses...';

    try {
      const res = await fetch(BASE + 'api/checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          _csrf: window.CSRF || '',
          client_ref: clientRef,
          tipe: $('#tipe').value,
          table_id: $('#tipe').value === 'dine_in' ? ($('#table_id').value || null) : null,
          nama_pelanggan: $('#nama_pelanggan').value,
          catatan: $('#pay-catatan').value,
          metode_bayar: $('#metode').value,
          diskon_persen: t.dp,
          diskon_alasan: $('#diskon-alasan').value,
          supervisor_pin: $('#supervisor-pin').value,
          bayar: bayar,
          items: cart.map(c => ({ product_id: c.product_id, qty: c.qty, pilihan: c.pilihan, catatan: c.catatan }))
        })
      });
      let data;
      try { data = await res.json(); } catch (e) { throw new Error('Respon server tidak valid. Cek koneksi lalu coba lagi.'); }
      if (res.status === 401) { window.location = BASE + 'login.php'; return; }
      if (data.need_shift) {
        KasirKu.toast(data.message, 'warning', 5000);
        setTimeout(() => { window.location = BASE + 'shift.php'; }, 1200);
        return;
      }
      if (data.need_pin) {
        $('#diskon-auth').classList.remove('hidden');
        $('#pin-wrap').classList.remove('hidden');
        $('#supervisor-pin').focus();
      }
      if (!data.ok) throw new Error(data.message || 'Gagal memproses transaksi.');

      cart = [];
      clientRef = null;
      saveCart();
      window.location = BASE + 'order-detail.php?id=' + data.id + '&print=1';
    } catch (err) {
      KasirKu.toast(err.message, 'danger', 4500);
      btn.disabled = false;
      btn.textContent = 'Proses & Cetak Struk';
    }
  }

  /* ---------------- Open bill di server ---------------- */
  async function kirimBill(url, body, btn, label) {
    if (!cart.length) return;
    btn.disabled = true; btn.textContent = 'Menyimpan...';
    try {
      const res = await fetch(BASE + url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({ _csrf: window.CSRF || '',
          items: cart.map(c => ({ product_id: c.product_id, qty: c.qty, pilihan: c.pilihan, catatan: c.catatan })) }, body)) });
      const data = await res.json().catch(() => ({ ok: false, message: 'Respon server tidak valid.' }));
      if (res.status === 401) { window.location = BASE + 'login.php'; return; }
      if (!data.ok) throw new Error(data.message || 'Gagal menyimpan.');
      cart = []; saveCart();
      window.location = BASE + 'bill.php?id=' + data.id;
    } catch (err) {
      KasirKu.toast(err.message, 'danger', 4500);
      btn.disabled = false; btn.textContent = label;
    }
  }
  function simpanBill() {
    if ($('#tipe').value === 'dine_in' && !$('#table_id').value && !$('#nama_pelanggan').value.trim()) {
      KasirKu.toast('Pilih meja atau isi nama pelanggan untuk open bill.', 'warning'); return;
    }
    closeCart();
    kirimBill('api/checkout.php', { mode: 'simpan', client_ref: newRef(), tipe: $('#tipe').value,
      table_id: $('#tipe').value === 'dine_in' ? ($('#table_id').value || null) : null,
      nama_pelanggan: $('#nama_pelanggan').value }, $('#btn-simpan-bill'), 'Simpan Bill');
  }
  function tambahKeBill() {
    closeCart();
    kirimBill('api/bill.php', { action: 'tambah', order_id: CFG.billId }, $('#btn-tambah-bill'), 'Tambahkan ke Bill');
  }

  /* ---------------- Tahan pesanan (lokal di perangkat) ---------------- */
  function tahanPesanan() {
    if (!cart.length) { KasirKu.toast('Keranjang kosong.', 'warning'); return; }
    const nama = $('#nama_pelanggan').value.trim() || window.prompt('Nama / penanda pesanan:', '') || '';
    const held = getHeld();
    held.push({ nama: nama || ('Pesanan ' + (held.length + 1)), waktu: Date.now(), cart: cart,
      tipe: $('#tipe').value, table_id: $('#table_id').value, diskon: $('#diskon').value });
    setHeld(held);
    cart = []; clientRef = null;
    $('#nama_pelanggan').value = '';
    $('#diskon').value = 0;
    renderCart();
    KasirKu.toast('Pesanan ditahan.', 'info');
  }

  function bukaDitahan() {
    const held = getHeld();
    if (!held.length) { KasirKu.toast('Tidak ada pesanan yang ditahan.', 'info'); return; }
    const list = $('#held-list');
    list.innerHTML = held.map((h, i) => {
      const n = h.cart.reduce((s, c) => s + c.qty, 0);
      const jam = new Date(h.waktu).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
      return `<div class="held-row">
        <div style="flex:1;min-width:0"><strong>${esc(h.nama)}</strong><div class="muted" style="font-size:12px">${n} item &middot; ${jam}</div></div>
        <button class="btn btn-primary btn-sm" type="button" data-held-open="${i}">Lanjutkan</button>
        <button class="btn btn-ghost btn-sm" type="button" data-held-del="${i}" aria-label="Hapus">&times;</button>
      </div>`;
    }).join('');
    KasirKu.openModal('modal-held');
  }

  /* ---------------- Drawer keranjang (mobile) ---------------- */
  function openCart() { document.body.classList.add('cart-open'); }
  function closeCart() { document.body.classList.remove('cart-open'); }

  /* ---------------- Event ---------------- */
  document.addEventListener('click', function (ev) {
    const card = ev.target.closest('.prod-card');
    if (card) {
      const p = PRODUK.find(x => x.id === Number(card.dataset.id));
      if (!p) return;
      if (p.stok !== null && p.stok <= 0) { KasirKu.toast('Stok menu ini habis.', 'warning'); return; }
      if (p.varian && p.varian.length) openVarian(p);
      else addToCart(p, [], '');
      return;
    }

    const chip = ev.target.closest('.cat-chip');
    if (chip) {
      document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      filterKat = chip.dataset.kat;
      renderProduk();
      return;
    }

    const heldOpen = ev.target.closest('[data-held-open]');
    if (heldOpen) {
      const held = getHeld();
      const h = held.splice(Number(heldOpen.dataset.heldOpen), 1)[0];
      if (cart.length) {
        held.push({ nama: $('#nama_pelanggan').value.trim() || 'Pesanan ditukar', waktu: Date.now(), cart: cart,
          tipe: $('#tipe').value, table_id: $('#table_id').value, diskon: $('#diskon').value });
      }
      setHeld(held);
      cart = (h.cart || []).filter(c => PRODUK.some(p => p.id === c.product_id));
      $('#nama_pelanggan').value = h.nama || '';
      $('#tipe').value = h.tipe || 'dine_in';
      $('#table_id').value = h.table_id || '';
      $('#table_id').disabled = $('#tipe').value !== 'dine_in';
      $('#diskon').value = h.diskon || 0;
      clientRef = null;
      renderCart();
      KasirKu.closeModal('modal-held');
      return;
    }
    const heldDel = ev.target.closest('[data-held-del]');
    if (heldDel) {
      if (!confirm('Hapus pesanan yang ditahan ini?')) return;
      const held = getHeld();
      held.splice(Number(heldDel.dataset.heldDel), 1);
      setHeld(held);
      if (held.length) bukaDitahan(); else KasirKu.closeModal('modal-held');
      return;
    }

    const qtyBtn = ev.target.closest('.cart-item [data-act]');
    if (qtyBtn) {
      const item = qtyBtn.closest('.cart-item');
      const i = Number(item.dataset.i);
      const act = qtyBtn.dataset.act;
      if (act === 'plus') {
        const p = PRODUK.find(x => x.id === cart[i].product_id);
        if (p && !stokCukup(p, 1)) { KasirKu.toast('Stok ' + p.nama + ' tidak cukup.', 'warning'); return; }
        cart[i].qty++;
      } else if (act === 'minus') { cart[i].qty--; if (cart[i].qty <= 0) cart.splice(i, 1); }
      else if (act === 'del') cart.splice(i, 1);
      clientRef = null;
      renderCart();
      return;
    }

    const cash = ev.target.closest('[data-cash]');
    if (cash) {
      const v = cash.dataset.cash;
      const total = hitungTotal().total;
      $('#pay-input').value = v === 'exact' ? total : Number(v);
      hitungKembalian();
      return;
    }
  });

  document.addEventListener('input', function (ev) {
    if (ev.target.id === 'search-produk') {
      keyword = ev.target.value.trim().toLowerCase();
      renderProduk();
    }
    if (ev.target.id === 'pay-input') hitungKembalian();
    if (ev.target.id === 'diskon') { clientRef = null; hitungTotal(); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    $('#diskon').max = 100;
    loadCart();
    renderProduk();
    renderCart();
    renderHeldCount();

    $('#btn-bayar').addEventListener('click', function () { closeCart(); bukaBayar(); });
    $('#btn-proses').addEventListener('click', prosesBayar);
    $('#v-tambah').addEventListener('click', tambahDariVarian);
    $('#metode').addEventListener('change', toggleMetode);
    $('#hold-cart').addEventListener('click', tahanPesanan);
    if ($('#btn-simpan-bill')) $('#btn-simpan-bill').addEventListener('click', simpanBill);
    if ($('#btn-tambah-bill')) $('#btn-tambah-bill').addEventListener('click', tambahKeBill);
    $('#btn-held').addEventListener('click', bukaDitahan);
    $('#cart-fab').addEventListener('click', openCart);
    $('#cart-close').addEventListener('click', closeCart);
    $('#cart-overlay').addEventListener('click', closeCart);
    $('#clear-cart').addEventListener('click', function () {
      if (cart.length && confirm('Kosongkan keranjang?')) { cart = []; clientRef = null; renderCart(); }
    });
    $('#filter-favorit').addEventListener('click', function () {
      onlyFav = !onlyFav;
      this.classList.toggle('btn-accent', onlyFav);
      this.classList.toggle('btn-light', !onlyFav);
      renderProduk();
    });
    $('#tipe').addEventListener('change', function () {
      $('#table_id').disabled = this.value !== 'dine_in';
    });
  });
})();
