<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin', 'admin');

$me = current_user();
$outletId = active_outlet_id();
if (!$outletId) {
    set_flash('warning', 'Pilih cabang terlebih dahulu untuk mengatur pengaturan toko.');
    redirect('outlets.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nama   = trim((string)post('nama_resto', ''));
    $alamat = trim((string)post('alamat', ''));
    $telp   = trim((string)post('telepon', ''));
    $pajak  = min(100, max(0, (float)post('pajak_persen', 0)));
    $service = min(100, max(0, (float)post('service_persen', 0)));
    $footer = trim((string)post('footer_struk', ''));

    $maxDiskon = min(100, max(0, (float)post('max_diskon_kasir', 10)));
    $lebar = (int)post('lebar_struk', 58) === 80 ? 80 : 58;
    $nama = mb_substr($nama, 0, 120); $alamat = mb_substr($alamat, 0, 255); $telp = mb_substr($telp, 0, 30); $footer = mb_substr($footer, 0, 255);

    $old = outlet_settings($outletId);
    $logo = $old['logo'];
    try {
        $newLogo = save_uploaded_image('logo', 'logo_' . $outletId, 1048576);
    } catch (RuntimeException $ex) {
        set_flash('danger', $ex->getMessage());
        redirect('settings.php');
    }
    if ($newLogo) { delete_upload($logo); $logo = $newLogo; }
    if (post('hapus_logo') && $logo && !$newLogo) { delete_upload($logo); $logo = null; }
    $qris = $old['qris_image'];
    try {
        $newQris = save_uploaded_image('qris', 'qris_' . $outletId, 2097152);
    } catch (RuntimeException $ex) {
        set_flash('danger', 'QRIS: ' . $ex->getMessage());
        redirect('settings.php');
    }
    if ($newQris) { delete_upload($qris); $qris = $newQris; }
    if (post('hapus_qris') && $qris && !$newQris) { delete_upload($qris); $qris = null; }

    q('INSERT INTO settings (outlet_id, nama_resto, alamat, telepon, pajak_persen, service_persen, max_diskon_kasir, lebar_struk, footer_struk, logo, qris_image)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE nama_resto=VALUES(nama_resto), alamat=VALUES(alamat), telepon=VALUES(telepon), pajak_persen=VALUES(pajak_persen),
         service_persen=VALUES(service_persen), max_diskon_kasir=VALUES(max_diskon_kasir), lebar_struk=VALUES(lebar_struk),
         footer_struk=VALUES(footer_struk), logo=VALUES(logo), qris_image=VALUES(qris_image)',
      [$outletId, $nama, $alamat, $telp, $pajak, $service, $maxDiskon, $lebar, $footer, $logo, $qris]);
    $chg = [];
    foreach (['pajak_persen' => $pajak, 'service_persen' => $service, 'max_diskon_kasir' => $maxDiskon] as $k => $v) {
        if ((float)$old[$k] != $v) $chg[] = "$k {$old[$k]} -> $v";
    }
    if ($chg) log_activity('pengaturan', 'Ubah pengaturan: ' . implode(', ', $chg), $outletId);

    // Sinkronkan data ke tabel outlets
    q('UPDATE outlets SET nama = ?, alamat = ?, telepon = ? WHERE id = ?', [$nama ?: fetchCol('SELECT nama FROM outlets WHERE id=?', [$outletId]), $alamat, $telp, $outletId]);

    set_flash('success', 'Pengaturan toko berhasil disimpan.');
    redirect('settings.php');
}

$setting = fetchOne('SELECT * FROM settings WHERE outlet_id = ?', [$outletId]);
$outlet  = fetchOne('SELECT * FROM outlets WHERE id = ?', [$outletId]);

if (!$setting) {
    q('INSERT INTO settings (outlet_id) VALUES (?)', [$outletId]);
    $setting = fetchOne('SELECT * FROM settings WHERE outlet_id = ?', [$outletId]);
}

$page_title = 'Pengaturan Toko';
$page_sub   = 'Identitas & konfigurasi struk';
$active     = 'settings.php';
include __DIR__ . '/includes/header.php';
?>

<div class="grid" style="grid-template-columns: 1.2fr 1fr; align-items:start">
  <div class="card">
    <div class="card-head"><h3>Identitas Toko</h3></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-group">
          <label class="field-label">Nama Toko / Resto</label>
          <input class="input" type="text" name="nama_resto" value="<?= e($setting['nama_resto'] ?? $outlet['nama']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">Alamat</label>
          <input class="input" type="text" name="alamat" value="<?= e($setting['alamat'] ?? $outlet['alamat']) ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Telepon</label>
            <input class="input" type="text" name="telepon" value="<?= e($setting['telepon'] ?? $outlet['telepon']) ?>">
          </div>
          <div class="form-group">
            <label class="field-label">Logo Toko</label>
            <input class="input" type="file" name="logo" accept="image/*">
            <?php if (!empty($setting['logo']) && is_file(UPLOAD_PATH . '/' . $setting['logo'])): ?>
              <div class="hint" style="margin-top:6px">
                <img src="<?= e(UPLOAD_URL . $setting['logo']) ?>" style="height:40px;border-radius:8px;vertical-align:middle">
                <label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px">
                  <input type="checkbox" name="hapus_logo" value="1"> Hapus
                </label>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Pajak / PPN (%)</label>
            <input class="input mono" type="number" name="pajak_persen" min="0" max="100" step="0.5" value="<?= e($setting['pajak_persen']) ?>">
            <div class="hint">Dihitung otomatis oleh sistem (PB1/PPN), kasir tidak bisa mengubah.</div>
          </div>
          <div class="form-group">
            <label class="field-label">Service Charge (%)</label>
            <input class="input mono" type="number" name="service_persen" min="0" max="100" step="0.5" value="<?= e($setting['service_persen']) ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="field-label">Batas Diskon Kasir (%)</label>
            <input class="input mono" type="number" name="max_diskon_kasir" min="0" max="100" step="0.5" value="<?= e($setting['max_diskon_kasir']) ?>">
            <div class="hint">Di atas batas ini kasir wajib memasukkan PIN supervisor.</div>
          </div>
          <div class="form-group">
            <label class="field-label">Lebar Kertas Struk</label>
            <select class="select" name="lebar_struk">
              <option value="58"<?= (int)$setting['lebar_struk'] !== 80 ? ' selected' : '' ?>>58 mm</option>
              <option value="80"<?= (int)$setting['lebar_struk'] === 80 ? ' selected' : '' ?>>80 mm</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="field-label">Gambar QRIS Toko (statis)</label>
          <input class="input" type="file" name="qris" accept="image/png,image/jpeg,image/webp">
          <div class="hint">Unggah QRIS dari bank/penyedia Anda. Akan tampil di layar bayar saat metode QRIS dipilih.</div>
          <?php if (!empty($setting['qris_image'])): ?>
            <div class="hint" style="margin-top:6px"><img src="<?= e(UPLOAD_URL . $setting['qris_image']) ?>" style="height:60px;border-radius:6px;vertical-align:middle">
              <label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px"><input type="checkbox" name="hapus_qris" value="1"> Hapus</label></div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="field-label">Ucapan di Struk</label>
          <input class="input" type="text" name="footer_struk" value="<?= e($setting['footer_struk']) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Simpan Pengaturan</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Pratinjau Struk</h3></div>
    <div class="card-body">
      <div class="receipt" style="border:1px dashed var(--border);border-radius:10px;padding:16px">
        <div class="r-center">
          <strong><?= e($setting['nama_resto'] ?: $outlet['nama']) ?></strong><br>
          <?= e($setting['alamat'] ?: $outlet['alamat'] ?: '-') ?><br>
          Telp: <?= e($setting['telepon'] ?: $outlet['telepon'] ?: '-') ?>
        </div>
        <div class="r-line"></div>
        <table>
          <tr><td>No</td><td class="text-right">TRX-<?= date('ymd') ?>-0001</td></tr>
          <tr><td>Tanggal</td><td class="text-right"><?= tanggal_indo(date('Y-m-d H:i:s')) ?></td></tr>
          <tr><td>Kasir</td><td class="text-right"><?= e($me['nama']) ?></td></tr>
        </table>
        <div class="r-line"></div>
        <table>
          <tr><td colspan="2">Contoh Menu Kopi</td></tr>
          <tr><td>2 x 25.000</td><td class="text-right">50.000</td></tr>
          <tr><td colspan="2" style="font-size:11px;color:#555">Ice, Less Sugar</td></tr>
        </table>
        <div class="r-line"></div>
        <table>
          <tr><td>Subtotal</td><td class="text-right">50.000</td></tr>
          <tr><td>Pajak (<?= angka($setting['pajak_persen']) ?>%)</td><td class="text-right"><?= angka(hitung_total(50000, 0, (float)$setting['service_persen'], (float)$setting['pajak_persen'])['pajak']) ?></td></tr>
          <tr class="r-total"><td>Total</td><td class="text-right"><?= angka(hitung_total(50000, 0, (float)$setting['service_persen'], (float)$setting['pajak_persen'])['total']) ?></td></tr>
        </table>
        <div class="r-line"></div>
        <div class="r-center" style="font-size:11px"><?= e($setting['footer_struk']) ?></div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
