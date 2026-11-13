/* =====================================================================
   KasirKu POS - Helper UI umum
   ===================================================================== */
(function () {
  'use strict';

  const K = window.KasirKu = window.KasirKu || {};

  /* ---------------- Toast ---------------- */
  K.toast = function (message, type = 'info', duration = 3200) {
    let wrap = document.getElementById('toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    const icons = { success: '&#10003;', danger: '&#10007;', warning: '!', info: 'i' };
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = '<span class="t-ico" style="font-weight:800;color:var(--primary)">' +
      (icons[type] || 'i') + '</span><span class="t-msg"></span>';
    el.querySelector('.t-msg').textContent = message;
    wrap.appendChild(el);
    setTimeout(() => {
      el.classList.add('hide');
      setTimeout(() => el.remove(), 220);
    }, duration);
  };

  /* ---------------- Modal ---------------- */
  K.openModal = function (id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id;
    if (m) m.classList.add('open');
  };
  K.closeModal = function (id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id;
    if (m) m.classList.remove('open');
  };

  document.addEventListener('click', function (ev) {
    const opener = ev.target.closest('[data-modal-open]');
    if (opener) {
      ev.preventDefault();
      K.openModal(opener.getAttribute('data-modal-open'));
      return;
    }
    const closer = ev.target.closest('[data-modal-close]');
    if (closer) {
      ev.preventDefault();
      const m = closer.closest('.modal-backdrop');
      if (m) m.classList.remove('open');
      return;
    }
    if (ev.target.classList.contains('modal-backdrop')) {
      ev.target.classList.remove('open');
    }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    }
  });

  /* ---------------- Sidebar (mobile) ---------------- */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('#burger')) {
      document.querySelector('.sidebar')?.classList.toggle('open');
      document.querySelector('.sidebar-overlay')?.classList.toggle('open');
    }
    if (ev.target.closest('.sidebar-overlay')) {
      document.querySelector('.sidebar')?.classList.remove('open');
      ev.target.classList.remove('open');
    }
  });

  /* ---------------- Konfirmasi hapus ---------------- */
  document.addEventListener('submit', function (ev) {
    const form = ev.target;
    const msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
      return;
    }
    const ask = form.getAttribute('data-prompt');
    if (ask) {
      const val = window.prompt(ask, '');
      if (val === null || !val.trim()) {
        ev.preventDefault();
        if (val !== null) K.toast('Alasan wajib diisi.', 'warning');
        return;
      }
      form.querySelector('[name="' + form.getAttribute('data-prompt-field') + '"]').value = val.trim();
    }
  });

  /* ---------------- Format rupiah ---------------- */
  K.rupiah = function (n) {
    n = Number(n) || 0;
    return 'Rp ' + n.toLocaleString('id-ID');
  };
  K.angka = function (n) {
    return (Number(n) || 0).toLocaleString('id-ID');
  };

  /* ---------------- Fetch helper ---------------- */
  K.post = async function (url, data) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(data || {})
    });
    return res.json();
  };

  /* ---------------- Auto-hide flash via data attribute ---------------- */
  document.addEventListener('DOMContentLoaded', function () {
    const node = document.getElementById('flash-data');
    if (node) {
      try {
        JSON.parse(node.textContent).forEach(f => K.toast(f.message, f.type));
      } catch (e) { /* noop */ }
    }
  });
})();
