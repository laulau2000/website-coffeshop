<?php
require_once __DIR__ . '/includes/orders.php';
require_role('superadmin', 'admin', 'kasir');
$outletId = require_outlet();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)post('action', '');
    $shift = current_shift((int)$me['id'], $outletId);

    if ($action === 'buka') {
        $kasAwal = round(max(0, (float)post('kas_awal', 0)), 2);
        if ($shift) {
            set_flash('warning', 'Shift Anda sudah terbuka.');
        } elseif ($kasAwal > 100000000) {
            set_flash('danger', 'Kas awal tidak wajar.');
        } else {
            q('INSERT INTO shifts (outlet_id, user_id, dibuka_at, kas_awal) VALUES (?,?,NOW(),?)', [$outletId, $me['id'], $kasAwal]);
            log_activity('shift_buka', 'Buka shift, kas awal ' . rupiah($kasAwal));
            set_flash('success', 'Shift dibuka. Selamat bertugas!');
            redirect('pos.php');
        }
    } elseif ($action === 'kas' && $shift) {
        $tipe = post('tipe') === 'masuk' ? 'masuk' : 'keluar';
        $jumlah = round((float)post('jumlah', 0), 2);
        $ket = mb_substr(trim((string)post('keterangan', '')), 0, 150);
        if ($jumlah <= 0 || $jumlah > 100000000 || $ket === '') {
            set_flash('danger', 'Jumlah dan keterangan kas wajib diisi dengan benar.');
        } else {
            q('INSERT INTO shift_kas (shift_id, user_id, tipe, jumlah, keterangan) VALUES (?,?,?,?,?)', [$shift['id'], $me['id'], $tipe, $jumlah, $ket]);
            log_activity('kas_' . $tipe, 'Kas ' . $tipe . ' ' . rupiah($jumlah) . ' - ' . $ket);
            set_flash('success', 'Kas ' . $tipe . ' dicatat.');
        }
    } elseif ($action === 'tutup') {
        $sid = (int)post('shift_id', 0);
        $target = fetchOne('SELECT * FROM shifts WHERE id = ? AND outlet_id = ? AND ditutup_at IS NULL', [$sid, $outletId]);
        if (!$target || ((int)$target['user_id'] !== (int)$me['id'] && !can_manage())) {
            set_flash('danger', 'Shift tidak ditemukan atau bukan milik Anda.');
        } else {
            $dihitung = round(max(0, (float)post('kas_dihitung', 0)), 2);
            $sum = shift_summary($target);
            $selisih = round($dihitung - $sum['kas_seharusnya'], 2);
            q('UPDATE shifts SET ditutup_at = NOW(), ditutup_oleh = ?, kas_sistem = ?, kas_dihitung = ?, selisih = ?, ringkasan = ?, catatan = ? WHERE id = ? AND ditutup_at IS NULL',
              [$me['id'], $sum['kas_seharusnya'], $dihitung, $selisih, json_encode($sum, JSON_UNESCAPED_UNICODE),
               mb_substr(trim((string)post('catatan', '')), 0, 255), $sid]);
            log_activity('shift_tutup', 'Tutup shift #' . $sid . ': seharusnya ' . rupiah($sum['kas_seharusnya']) . ', dihitung ' . rupiah($dihitung) . ', selisih ' . rupiah($selisih));
            set_flash($selisih == 0 ? 'success' : 'warning', 'Shift ditutup. Selisih kas: ' . rupiah($selisih));
            redirect('shift.php?id=' . $sid);
        }
    }
    redirect('shift.php');
}

$viewId = (int)get('id', 0);
$shift = $viewId
    ? fetchOne('SELECT s.*, u.nama kasir FROM shifts s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.outlet_id = ?' . (can_manage() ? '' : ' AND s.user_id = ' . (int)$me['id']), [$viewId, $outletId])
    : fetchOne('SELECT s.*, u.nama kasir FROM shifts s JOIN users u ON u.id = s.user_id WHERE s.user_id = ? AND s.outlet_id = ? AND s.ditutup_at IS NULL', [$me['id'], $outletId]);
$sum = $shift ? ($shift['ditutup_at'] && $shift['ringkasan'] ? json_decode($shift['ringkasan'], true) : shift_summary($shift)) : null;
$kasList = $shift ? fetchAll('SELECT k.*, u.nama FROM shift_kas k LEFT JOIN users u ON u.id = k.user_id WHERE k.shift_id = ? ORDER BY k.id', [$shift['id']]) : [];
$riwayat = fetchAll('SELECT s.*, u.nama kasir FROM shifts s JOIN users u ON u.id = s.user_id WHERE s.outlet_id = ?' . (can_manage() ? '' : ' AND s.user_id = ' . (int)$me['id']) . ' ORDER BY s.id DESC LIMIT 30', [$outletId]);
$metodeLabel = ['cash' => 'Tunai', 'qris' => 'QRIS', 'debit' => 'Debit', 'kredit' => 'Kredit', 'ewallet' => 'E-Wallet', 'transfer' => 'Transfer'];
$autoPrint = (int)get('print', 0) === 1;

$page_title = 'Shift Kasir';
$page_sub   = 'Buka shift, kas masuk/keluar, dan tutup kasir';
$active     = 'shift.php';
include __DIR__ . '/includes/header.php';
?>
<?php if (!$shift): ?>
  <div class="card" style="max-width:460px">
    <div class="card-head"><h3>Buka Shift</h3></div>
    <div class="card-body">
      <p class="hint" style="margin-top:0">Hitung uang tunai di laci sebelum mulai melayani. Pembayaran hanya bisa diproses saat shift terbuka.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="buka">
        <div class="form-group"><label class="field-label">Kas awal (modal kembalian)</label>
          <input class="input mono" type="number" name="kas_awal" min="0" step="500" value="0" required inputmode="numeric"></div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Buka Shift</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="no-print" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
    <?php if ($viewId): ?><a class="btn btn-light" href="<?= e(BASE_URL) ?>shift.php">&larr; Shift saya</a><?php endif; ?>
    <button class="btn btn-light" type="button" onclick="window.print()"><?= icon('printer', 16) ?> Cetak Laporan Shift</button>
    <?php if (!$shift['ditutup_at']): ?><a class="btn btn-primary" href="<?= e(BASE_URL) ?>pos.php">Ke Kasir</a><?php endif; ?>
  </div>
  <div class="grid" style="grid-template-columns: 1fr 1fr; align-items:start">
    <div class="card">
      <div class="card-head"><h3>Shift #<?= (int)$shift['id'] ?> &middot; <?= e($shift['kasir']) ?></h3>
        <span class="badge <?= $shift['ditutup_at'] ? '' : 'badge-success' ?>" style="margin-left:auto"><?= $shift['ditutup_at'] ? 'Ditutup' : 'Berjalan' ?></span></div>
      <div class="card-body receipt" style="max-width:none">
        <table>
          <tr><td>Dibuka</td><td class="text-right"><?= e(tanggal_indo($shift['dibuka_at'])) ?></td></tr>
          <?php if ($shift['ditutup_at']): ?><tr><td>Ditutup</td><td class="text-right"><?= e(tanggal_indo($shift['ditutup_at'])) ?></td></tr><?php endif; ?>
          <tr><td>Jumlah transaksi</td><td class="text-right"><?= angka($sum['trx']) ?></td></tr>
        </table>
        <div class="r-line"></div>
        <table>
          <?php foreach ($sum['per_metode'] as $m): ?>
            <tr><td><?= e($metodeLabel[$m['metode_bayar']] ?? $m['metode_bayar']) ?> (<?= angka($m['trx']) ?>)</td><td class="text-right"><?= angka($m['total']) ?></td></tr>
          <?php endforeach; ?>
          <tr class="r-total"><td>Total penjualan</td><td class="text-right"><?= angka($sum['omzet']) ?></td></tr>
          <tr><td>Diskon diberikan</td><td class="text-right"><?= angka($sum['diskon']) ?></td></tr>
          <tr><td>Transaksi batal</td><td class="text-right"><?= angka($sum['batal_trx']) ?> (<?= angka($sum['batal_total']) ?>)</td></tr>
        </table>
        <div class="r-line"></div>
        <table>
          <tr><td>Kas awal</td><td class="text-right"><?= angka($shift['kas_awal']) ?></td></tr>
          <tr><td>+ Penjualan tunai</td><td class="text-right"><?= angka($sum['tunai']) ?></td></tr>
          <tr><td>+ Kas masuk</td><td class="text-right"><?= angka($sum['kas_masuk']) ?></td></tr>
          <tr><td>- Kas keluar</td><td class="text-right"><?= angka($sum['kas_keluar']) ?></td></tr>
          <tr class="r-total"><td>Kas seharusnya</td><td class="text-right"><?= angka($sum['kas_seharusnya']) ?></td></tr>
          <?php if ($shift['ditutup_at']): ?>
            <tr><td>Kas dihitung</td><td class="text-right"><?= angka($shift['kas_dihitung']) ?></td></tr>
            <tr class="r-total"><td>Selisih</td><td class="text-right" style="color:<?= (float)$shift['selisih'] < 0 ? 'var(--danger)' : 'inherit' ?>"><?= angka($shift['selisih']) ?></td></tr>
            <?php if ($shift['catatan']): ?><tr><td colspan="2">Catatan: <?= e($shift['catatan']) ?></td></tr><?php endif; ?>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <div class="no-print">
      <?php if (!$shift['ditutup_at']): ?>
      <div class="card mb-3">
        <div class="card-head"><h3>Kas Masuk / Keluar</h3></div>
        <div class="card-body">
          <?php if ((int)$shift['user_id'] === (int)$me['id']): ?>
          <form method="post" class="form-row" style="grid-template-columns: 110px 1fr; gap:10px">
            <?= csrf_field() ?><input type="hidden" name="action" value="kas">
            <select class="select" name="tipe"><option value="keluar">Keluar</option><option value="masuk">Masuk</option></select>
            <input class="input mono" type="number" name="jumlah" min="1" step="500" placeholder="Jumlah" required>
            <input class="input" type="text" name="keterangan" maxlength="150" placeholder="Keterangan, mis. beli es batu" required style="grid-column:1/-1">
            <button class="btn btn-light" type="submit" style="grid-column:1/-1">Catat</button>
          </form>
          <?php endif; ?>
          <?php if ($kasList): ?>
          <table class="data mt-2" style="font-size:13px">
            <?php foreach ($kasList as $k): ?>
              <tr><td><?= e(date('H:i', strtotime($k['created_at']))) ?></td><td><?= e($k['keterangan']) ?></td>
                <td class="text-right mono" style="color:<?= $k['tipe'] === 'keluar' ? 'var(--danger)' : 'var(--primary)' ?>"><?= $k['tipe'] === 'keluar' ? '-' : '+' ?><?= angka($k['jumlah']) ?></td></tr>
            <?php endforeach; ?>
          </table>
          <?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h3>Tutup Shift</h3></div>
        <div class="card-body">
          <form method="post" data-confirm="Tutup shift sekarang? Setelah ditutup, pembayaran baru memerlukan shift baru.">
            <?= csrf_field() ?><input type="hidden" name="action" value="tutup"><input type="hidden" name="shift_id" value="<?= (int)$shift['id'] ?>">
            <div class="form-group"><label class="field-label">Uang tunai yang dihitung di laci</label>
              <input class="input mono" type="number" name="kas_dihitung" min="0" step="500" required inputmode="numeric"></div>
            <div class="form-group"><label class="field-label">Catatan (opsional)</label>
              <input class="input" type="text" name="catatan" maxlength="255"></div>
            <button class="btn btn-primary btn-block" type="submit">Tutup Shift &amp; Hitung Selisih</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-3 no-print">
  <div class="card-head"><h3>Riwayat Shift</h3></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>#</th><th>Kasir</th><th>Dibuka</th><th>Ditutup</th><th class="text-right">Kas Seharusnya</th><th class="text-right">Dihitung</th><th class="text-right">Selisih</th><th></th></tr></thead>
      <tbody>
      <?php if (!$riwayat): ?><tr><td colspan="8" class="text-center muted" style="padding:24px">Belum ada shift.</td></tr><?php endif; ?>
      <?php foreach ($riwayat as $r): ?>
        <tr>
          <td class="mono"><?= (int)$r['id'] ?></td><td><?= e($r['kasir']) ?></td>
          <td class="muted nowrap"><?= e(tanggal_indo($r['dibuka_at'])) ?></td>
          <td class="muted nowrap"><?= $r['ditutup_at'] ? e(tanggal_indo($r['ditutup_at'])) : '<span class="badge badge-success">Berjalan</span>' ?></td>
          <td class="text-right mono"><?= $r['kas_sistem'] !== null ? rupiah($r['kas_sistem']) : '-' ?></td>
          <td class="text-right mono"><?= $r['kas_dihitung'] !== null ? rupiah($r['kas_dihitung']) : '-' ?></td>
          <td class="text-right mono" style="color:<?= (float)$r['selisih'] < 0 ? 'var(--danger)' : 'inherit' ?>"><?= $r['selisih'] !== null ? rupiah($r['selisih']) : '-' ?></td>
          <td class="text-right"><a class="btn btn-light btn-sm" href="<?= e(BASE_URL) ?>shift.php?id=<?= (int)$r['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($autoPrint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
