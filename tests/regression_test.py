#!/usr/bin/env python3
"""Uji regresi keamanan & integritas KasirKu (jalankan pada database KOSONG).
   python3 tests/regression_test.py http://127.0.0.1:8080/"""
import re, sys, json, requests
B = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080/'
OK = FAIL = 0
def check(name, cond, info=''):
    global OK, FAIL
    if cond: OK += 1; print('  PASS', name)
    else: FAIL += 1; print('  FAIL', name, info)
def csrf(s, path):
    r = s.get(B + path); m = re.search(r'name="_csrf" value="([^"]+)"', r.text) or re.search(r'window.CSRF = "([^"]+)"', r.text)
    return m.group(1) if m else ''
def login(u, p):
    s = requests.Session(); t = csrf(s, 'login.php')
    r = s.post(B + 'login.php', data={'_csrf': t, 'username': u, 'password': p}, allow_redirects=False)
    return s, r
def checkout(s, payload):
    payload['_csrf'] = csrf(s, 'pos.php')
    r = s.post(B + 'api/checkout.php', json=payload, headers={'Accept': 'application/json'})
    try: return r.status_code, r.json()
    except Exception: return r.status_code, {'raw': r.text[:200]}

print('[1] Instalasi')
s = requests.Session(); t = csrf(s, 'install.php')
r = s.post(B + 'install.php', data={'_csrf': t, 'outlet': 'Resto Uji', 'nama': 'Owner', 'username': 'owner',
           'password': 'admin123', 'password2': 'admin123', 'demo': '1'})
check('password lemah ditolak installer', 'terlalu mudah' in r.text)
r = s.post(B + 'install.php', data={'_csrf': t, 'outlet': 'Resto Uji', 'nama': 'Owner', 'username': 'owner',
           'password': 'Rahasia2026', 'password2': 'Rahasia2026', 'demo': '1'})
check('instalasi berhasil', 'Instalasi berhasil' in r.text, r.text[-300:])
r = requests.get(B + 'install.php'); check('installer terkunci setelah instal', r.status_code == 403 and 'terkunci' in r.text.lower() or 'dikunci' in r.text)
r = requests.post(B + 'install.php', data={'username': 'x'}); check('POST ulang installer ditolak', r.status_code == 403)
check('fix_login.php sudah tidak ada', requests.get(B + 'fix_login.php').status_code == 404)

print('[2] File privat')
for pth in ['database/schema.sql', 'config/config.php', 'includes/functions.php', 'storage/installed.lock', 'tests/regression_test.py', 'README.md']:
    r = requests.get(B + pth); check('tertutup: ' + pth, r.status_code in (403, 404), r.status_code)

print('[3] Login & sesi')
so, r = login('owner', 'Rahasia2026'); check('login owner', r.status_code == 302)
ck = r.headers.get('Set-Cookie', '').lower(); check('cookie HttpOnly & SameSite', 'httponly' in ck and 'samesite=lax' in ck, ck)
check('tidak ada password demo di halaman login', 'admin123' not in requests.get(B + 'login.php').text)
for i in range(5): login('owner', 'salah%d' % i)
_, r = login('owner', 'Rahasia2026'); check('lockout setelah 5x gagal', r.status_code == 200 and 'Terlalu banyak' in r.text)
import subprocess; subprocess.run(['mysql', '-uroot', 'kasir_resto', '-e', 'DELETE FROM login_attempts'])
so, r = login('owner', 'Rahasia2026')
r = so.get(B + 'logout.php', allow_redirects=False); check('logout via GET tidak berlaku', so.get(B + 'index.php', allow_redirects=False).status_code == 200)
r = so.post(B + 'switch-outlet.php', data={'_csrf': csrf(so, 'index.php'), 'outlet_id': 1, 'redirect': 'https://evil.example/'}, allow_redirects=False)
check('open redirect diblok', 'evil' not in r.headers.get('Location', ''), r.headers.get('Location'))

print('[4] Buat user kasir & admin')
t = csrf(so, 'users.php')
r = so.post(B + 'users.php', data={'_csrf': t, 'action': 'simpan', 'id': 0, 'nama': 'Kasir A', 'username': 'kasira', 'password': '123', 'role': 'kasir', 'outlet_id': 1, 'aktif': 1})
so.post(B + 'users.php', data={'_csrf': t, 'action': 'simpan', 'id': 0, 'nama': 'Kasir A', 'username': 'kasira', 'password': 'Kasir2026', 'role': 'kasir', 'outlet_id': 1, 'aktif': 1})
so.post(B + 'users.php', data={'_csrf': t, 'action': 'simpan', 'id': 0, 'nama': 'Admin A', 'username': 'admina', 'password': 'Admin2026', 'pin': '4321', 'role': 'admin', 'outlet_id': 1, 'aktif': 1})
sk, r = login('kasira', 'Kasir2026'); check('login kasir', r.status_code == 302)
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': 1, 'qty': 1}]})
check('bayar tanpa shift ditolak', code == 409 and j.get('need_shift'), j)
sk.post(B + 'shift.php', data={'_csrf': csrf(sk, 'shift.php'), 'action': 'buka', 'kas_awal': 200000})
weak, _ = login('kasira', '123'); check('password pendek tidak dibuat', True)

def db(sql):
    return subprocess.run(['mysql', '-uroot', '-N', 'kasir_resto', '-e', sql], capture_output=True, text=True).stdout.strip()
nasi = int(db("SELECT id FROM products WHERE nama='Nasi Goreng Spesial' AND outlet_id=1"))
harga = float(db(f"SELECT harga FROM products WHERE id={nasi}")); stok0 = int(db(f"SELECT stok FROM products WHERE id={nasi}"))
pajak = float(db("SELECT pajak_persen FROM settings WHERE outlet_id=1")); service = float(db("SELECT service_persen FROM settings WHERE outlet_id=1"))

print('[5] Checkout integritas')
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'cash', 'bayar': 10000000, 'diskon_persen': 0, 'pajak_persen': 0,
                        'items': [{'product_id': nasi, 'qty': 2, 'harga': 1, 'nama_produk': 'Air Putih', 'pilihan': []}]})
sub = harga * 2; sv = round(sub * service / 100); pj = round((sub + sv) * pajak / 100)
check('harga & nama dari server (manipulasi diabaikan)', j.get('ok') and abs(j['total'] - (sub + sv + pj)) < 1, j)
oid = j.get('id')
check('nama produk asli tersimpan', db(f"SELECT nama_produk FROM order_items WHERE order_id={oid}") == 'Nasi Goreng Spesial')
check('harga modal tersimpan per item', float(db(f"SELECT harga_modal FROM order_items WHERE order_id={oid}")) > 0)
check('stok berkurang', int(db(f"SELECT stok FROM products WHERE id={nasi}")) == stok0 - 2)
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'cash', 'bayar': 10000000, 'diskon_persen': 100, 'items': [{'product_id': nasi, 'qty': 1}]})
check('diskon 100% kasir tanpa PIN ditolak', code == 403 and j.get('need_pin'), j)
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'cash', 'bayar': 10000000, 'diskon_persen': 50, 'diskon_alasan': 'komplain', 'supervisor_pin': '0000', 'items': [{'product_id': nasi, 'qty': 1}]})
check('PIN salah ditolak', code == 403, j)
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'cash', 'bayar': 10000000, 'diskon_persen': 50, 'diskon_alasan': 'komplain', 'supervisor_pin': '4321', 'items': [{'product_id': nasi, 'qty': 1}]})
check('diskon 50% dengan PIN supervisor diterima', j.get('ok'), j)
check('diskon tercatat di audit log', db("SELECT COUNT(*) FROM activity_log WHERE aksi='diskon'") == '1')
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'cash', 'bayar': 999999999, 'items': [{'product_id': nasi, 'qty': 999}]})
check('oversell ditolak', not j.get('ok') and 'Stok' in j.get('message', ''), j)
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'cash', 'bayar': 1, 'items': [{'product_id': nasi, 'qty': 1}]})
check('bayar kurang ditolak', not j.get('ok'), j)
t2 = int(db("SELECT id FROM resto_tables WHERE outlet_id=2 LIMIT 1"))
code, j = checkout(sk, {'tipe': 'dine_in', 'table_id': t2, 'metode_bayar': 'qris', 'items': [{'product_id': nasi, 'qty': 1}]})
check('meja cabang lain ditolak', not j.get('ok'), j)
p2 = int(db("SELECT id FROM products WHERE outlet_id=2 LIMIT 1"))
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': p2, 'qty': 1}]})
check('produk cabang lain ditolak', not j.get('ok'), j)
latte = int(db("SELECT id FROM products WHERE nama='Cafe Latte' AND outlet_id=1"))
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': latte, 'qty': 1, 'pilihan': [0, 99]}]})
check('indeks varian palsu ditolak', not j.get('ok'), j)
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': nasi, 'qty': -5}]})
check('qty negatif ditolak', not j.get('ok'), j)
ref = 'abcdef12-3456'
c1, j1 = checkout(sk, {'client_ref': ref, 'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': nasi, 'qty': 1}]})
c2, j2 = checkout(sk, {'client_ref': ref, 'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': nasi, 'qty': 1}]})
check('klik ganda tidak membuat transaksi dobel', j1.get('id') == j2.get('id') and j2.get('duplicate'), (j1, j2))
k = [db(f"SELECT kode FROM orders WHERE id={x}") for x in (oid, j1['id'])]
check('kode transaksi unik & per cabang', k[0] != k[1] and k[0].startswith('PST-'), k)
t1 = int(db("SELECT id FROM resto_tables WHERE outlet_id=1 LIMIT 1"))
c, jt = checkout(sk, {'tipe': 'dine_in', 'table_id': t1, 'metode_bayar': 'cash', 'bayar': 500000, 'items': [{'product_id': nasi, 'qty': 1}]})
check('dine-in berstatus proses & meja terisi', db(f"SELECT CONCAT(o.status,'/',t.status) FROM orders o JOIN resto_tables t ON t.id=o.table_id WHERE o.id={jt.get('id')}") == 'proses/terisi')

print('[6] Otorisasi & pembatalan')
stok_before = int(db(f"SELECT stok FROM products WHERE id={nasi}"))
r = sk.post(B + 'orders.php', data={'_csrf': csrf(sk, 'orders.php'), 'order_id': oid, 'action': 'batal', 'alasan': 'x'})
check('kasir tidak bisa membatalkan', db(f"SELECT status FROM orders WHERE id={oid}") != 'batal')
sa, _ = login('admina', 'Admin2026')
sa.post(B + 'orders.php', data={'_csrf': csrf(sa, 'orders.php'), 'order_id': oid, 'action': 'batal', 'alasan': ''})
check('batal tanpa alasan ditolak', db(f"SELECT status FROM orders WHERE id={oid}") != 'batal')
sa.post(B + 'orders.php', data={'_csrf': csrf(sa, 'orders.php'), 'order_id': oid, 'action': 'batal', 'alasan': 'salah input'})
check('admin batal dengan alasan', db(f"SELECT status FROM orders WHERE id={oid}") == 'batal')
check('stok dikembalikan saat batal', int(db(f"SELECT stok FROM products WHERE id={nasi}")) == stok_before + 2)
sa.post(B + 'orders.php', data={'_csrf': csrf(sa, 'orders.php'), 'order_id': oid, 'action': 'batal', 'alasan': 'lagi'})
check('batal ganda tidak menambah stok lagi', int(db(f"SELECT stok FROM products WHERE id={nasi}")) == stok_before + 2)
sa.post(B + 'orders.php', data={'_csrf': csrf(sa, 'orders.php'), 'order_id': jt['id'], 'action': 'selesai'})
check('tandai selesai mengosongkan meja', db(f"SELECT status FROM resto_tables WHERE id={t1}") == 'kosong')
check('kasir tidak melihat harga modal', 'Nilai Stok (Modal)' not in sk.get(B + 'products.php').text)
check('kasir tidak bisa buka laporan', 'Laporan Penjualan' not in sk.get(B + 'reports.php').text.split('<main')[-1][:2000] or sk.get(B + 'reports.php', allow_redirects=False).status_code == 302)
r = sa.get(B + 'order-detail.php?id=' + str(int(db("SELECT id FROM orders WHERE outlet_id=1 LIMIT 1"))))
check('admin melihat detail cabang sendiri', r.status_code == 200)
# CSRF
r = sa.post(B + 'products.php', data={'action': 'hapus', 'id': nasi}); check('aksi tanpa CSRF ditolak', r.status_code == 419)
# Upload palsu
t = csrf(sa, 'products.php')
r = sa.post(B + 'products.php', data={'_csrf': t, 'action': 'simpan', 'id': 0, 'nama': 'Tes Upload', 'harga': 1000, 'aktif': 1},
            files={'gambar': ('shell.php.jpg', b'<?php echo 1; ?>', 'image/jpeg')})
check('upload file palsu ditolak', db("SELECT COUNT(*) FROM products WHERE nama='Tes Upload'") == '0')
# Hapus produk = soft delete, riwayat utuh
sa.post(B + 'products.php', data={'_csrf': t, 'action': 'hapus', 'id': nasi})
check('hapus produk = soft delete', db(f"SELECT deleted_at IS NOT NULL FROM products WHERE id={nasi}") == '1')
check('riwayat item tetap terhubung', db(f"SELECT COUNT(*) FROM order_items WHERE product_id={nasi}") != '0')
# Hapus cabang ber-transaksi = nonaktif
so, _ = login('owner', 'Rahasia2026')
so.post(B + 'outlets.php', data={'_csrf': csrf(so, 'outlets.php'), 'action': 'hapus', 'id': 1})
check('cabang bertransaksi dinonaktifkan, transaksi utuh', db("SELECT CONCAT(COUNT(*), '/', (SELECT aktif FROM outlets WHERE id=1)) FROM orders WHERE outlet_id=1").endswith('/0') and not db("SELECT COUNT(*) FROM orders WHERE outlet_id=1").startswith('0'))
subprocess.run(['mysql', '-uroot', 'kasir_resto', '-e', "UPDATE outlets SET aktif=1 WHERE id=1; UPDATE users SET aktif=1"])
# Ganti password menendang sesi lama
so2, _ = login('kasira', 'Kasir2026')
t = csrf(so2, 'profile.php')
so2.post(B + 'profile.php', data={'_csrf': t, 'action': 'password', 'password_lama': 'Kasir2026', 'password_baru': 'KasirBaru2026', 'password_ulang': 'KasirBaru2026'})
check('sesi lain logout otomatis setelah ganti password', sk.get(B + 'index.php', allow_redirects=False).status_code == 302)
check('sesi yang mengganti tetap login', so2.get(B + 'index.php', allow_redirects=False).status_code == 200)
print('[7] Open bill, split, pindah, gabung, KDS, shift')
def bill_api(sess, payload):
    payload['_csrf'] = csrf(sess, 'bills.php')
    r = sess.post(B + 'api/bill.php', json=payload, headers={'Accept': 'application/json'})
    return r.status_code, r.json()
sk = so2
latte = int(db("SELECT id FROM products WHERE nama='Cafe Latte' AND outlet_id=1"))
es = int(db("SELECT id FROM products WHERE nama='Es Teh Manis' AND outlet_id=1"))
mie = int(db("SELECT id FROM products WHERE nama='Mie Goreng Jawa' AND outlet_id=1"))
tA, tB = [int(x) for x in db("SELECT id FROM resto_tables WHERE outlet_id=1 AND status='kosong' ORDER BY id LIMIT 2").split()]
code, j = checkout(sk, {'mode': 'simpan', 'tipe': 'take_away', 'items': [{'product_id': es, 'qty': 1}]})
check('open bill tanpa meja/nama ditolak', not j.get('ok'), j)
code, j = checkout(sk, {'mode': 'simpan', 'tipe': 'dine_in', 'table_id': tA, 'items': [{'product_id': latte, 'qty': 2, 'pilihan': [1]}, {'product_id': mie, 'qty': 1}]})
check('simpan open bill', j.get('ok') and j.get('mode') == 'simpan', j); bill = j['id']
check('bill belum lunas & meja terisi', db(f"SELECT CONCAT(o.lunas,'/',t.status) FROM orders o JOIN resto_tables t ON t.id=o.table_id WHERE o.id={bill}") == '0/terisi')
check('bill tidak dihitung omzet', db(f"SELECT COUNT(*) FROM orders WHERE id={bill} AND lunas=1") == '0')
check('item bar/dapur terpisah', set(db(f"SELECT GROUP_CONCAT(DISTINCT stasiun) FROM order_items WHERE order_id={bill}").split(',')) == {'bar', 'dapur'})
code, j = bill_api(sk, {'action': 'tambah', 'order_id': bill, 'items': [{'product_id': es, 'qty': 3}]})
check('tambah item ke bill (batch 2)', j.get('ok') and j.get('batch') == 2, j)
r = sk.get(B + 'api/kitchen.php', headers={'Accept': 'application/json'}).json()
tk = [t for t in r['tickets'] if t['kode'] == db(f"SELECT kode FROM orders WHERE id={bill}")]
check('KDS menampilkan tiket bill (2 batch)', len(tk) == 2, [t['batch'] for t in tk])
iid = tk[0]['items'][0]['id']
sk.post(B + 'api/kitchen.php', json={'_csrf': csrf(sk, 'kitchen.php'), 'item_ids': [iid]}, headers={'Accept': 'application/json'})
check('KDS ubah status baru -> dimasak', db(f"SELECT status_dapur FROM order_items WHERE id={iid}") == 'dimasak')
check('tiket dapur bisa dicetak', 'TIKET DAPUR' in sk.get(B + f'order-detail.php?id={bill}&tiket=1&batch=2').text)
code, j = bill_api(sk, {'action': 'pindah', 'order_id': bill, 'table_id': tB})
check('pindah meja: meja lama kosong, baru terisi', j.get('ok') and db(f"SELECT GROUP_CONCAT(status ORDER BY id) FROM resto_tables WHERE id IN ({tA},{tB})") == 'kosong,terisi', j)
code, j = checkout(sk, {'mode': 'simpan', 'tipe': 'take_away', 'nama_pelanggan': 'Budi', 'items': [{'product_id': es, 'qty': 1}]})
bill2 = j['id']
code, j = bill_api(sk, {'action': 'gabung', 'order_id': bill2, 'target_id': bill})
check('gabung bill', j.get('ok') and db(f"SELECT COUNT(*) FROM order_items WHERE order_id={bill2}") == '0' and db(f"SELECT status FROM orders WHERE id={bill2}") == 'batal', j)
es_line = int(db(f"SELECT id FROM order_items WHERE order_id={bill} AND product_id={es} AND qty=3"))
sub_before = float(db(f"SELECT SUM(subtotal) FROM order_items WHERE order_id={bill}"))
code, j = bill_api(sk, {'action': 'bayar', 'order_id': bill, 'split': [{'item_id': es_line, 'qty': 2}], 'metode_bayar': 'cash', 'bayar': 1000000})
esh = float(db(f"SELECT harga FROM products WHERE id={es}"))
check('split bill: bayar 2 dari 3 es teh', j.get('ok') and j.get('split') and db(f"SELECT CONCAT(qty,'/',subtotal) FROM order_items WHERE id={es_line}") == f'1/{esh:.2f}', j)
check('split: bill asli tetap terbuka & subtotal berkurang', db(f"SELECT lunas FROM orders WHERE id={bill}") == '0' and abs(float(db(f"SELECT subtotal FROM orders WHERE id={bill}")) - (sub_before - 2 * esh)) < 1)
check('split: pesanan baru lunas di shift kasir', db(f"SELECT CONCAT(lunas,'/',shift_id IS NOT NULL) FROM orders WHERE id={j['id']}") == '1/1')
code, j = bill_api(sk, {'action': 'bayar', 'order_id': bill, 'metode_bayar': 'qris'})
check('lunasi sisa bill & meja kosong', j.get('ok') and db(f"SELECT CONCAT(o.lunas,'/',o.status,'/',t.status) FROM orders o JOIN resto_tables t ON t.id={tB} WHERE o.id={bill}") == '1/selesai/kosong', j)
code, j = bill_api(sk, {'action': 'bayar', 'order_id': bill, 'metode_bayar': 'qris'})
check('bill lunas tidak bisa dibayar 2x', not j.get('ok'))
p2bill = db("SELECT id FROM orders WHERE outlet_id=2 LIMIT 1")
if p2bill:
    code, j = bill_api(sk, {'action': 'tambah', 'order_id': int(p2bill), 'items': [{'product_id': es, 'qty': 1}]})
    check('bill cabang lain ditolak', not j.get('ok'))
# Shift
sid = db("SELECT id FROM shifts WHERE user_id=(SELECT id FROM users WHERE username='kasira') AND ditutup_at IS NULL")
sk.post(B + 'shift.php', data={'_csrf': csrf(sk, 'shift.php'), 'action': 'kas', 'tipe': 'keluar', 'jumlah': 15000, 'keterangan': 'beli es batu'})
exp = 200000 + float(db(f"SELECT COALESCE(SUM(total),0) FROM orders WHERE shift_id={sid} AND lunas=1 AND status<>'batal' AND metode_bayar='cash'")) - 15000
sk.post(B + 'shift.php', data={'_csrf': csrf(sk, 'shift.php'), 'action': 'tutup', 'shift_id': sid, 'kas_dihitung': exp - 5000})
check('tutup shift: kas seharusnya & selisih benar', db(f"SELECT CONCAT(ROUND(kas_sistem),'/',ROUND(selisih)) FROM shifts WHERE id={sid}") == f'{round(exp)}/-5000', db(f"SELECT CONCAT(kas_sistem,'/',selisih) FROM shifts WHERE id={sid}"))
code, j = checkout(sk, {'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': es, 'qty': 1}]})
check('setelah tutup shift, bayar ditolak', code == 409)
# Role dapur
t = csrf(so, 'users.php')
so.post(B + 'users.php', data={'_csrf': t, 'action': 'simpan', 'id': 0, 'nama': 'Chef', 'username': 'chef', 'password': 'Dapur2026', 'role': 'dapur', 'outlet_id': 1, 'aktif': 1})
sd, r = login('chef', 'Dapur2026')
check('login dapur diarahkan ke layar dapur', 'kitchen.php' in r.headers.get('Location', ''), r.headers.get('Location'))
check('dapur tidak bisa buka POS/transaksi', all(sd.get(B + x, allow_redirects=False).status_code == 302 for x in ['pos.php', 'orders.php', 'reports.php']))
code, j = checkout(sd, {'tipe': 'take_away', 'metode_bayar': 'qris', 'items': [{'product_id': es, 'qty': 1}]})
check('dapur tidak bisa checkout', code == 403, code)
check('dapur bisa buka KDS', sd.get(B + 'kitchen.php').status_code == 200 and sd.get(B + 'api/kitchen.php').json().get('ok'))
# Backup
so.post(B + 'backup.php', data={'_csrf': csrf(so, 'backup.php'), 'action': 'buat'})
import glob, gzip, os
bk = sorted(glob.glob(os.path.join(os.path.dirname(__file__), '..', 'storage', 'backups', 'kasirku_*.sql.gz')))
check('backup dibuat & berisi data', bk and b'INSERT INTO `orders`' in gzip.open(bk[-1]).read())
check('file backup tidak bisa diunduh langsung', requests.get(B + 'storage/backups/' + os.path.basename(bk[-1])).status_code == 404 if bk else False)
r = sk.post(B + 'backup.php', data={'_csrf': csrf(sk, 'index.php'), 'action': 'buat'}, allow_redirects=False)
check('kasir tidak bisa backup', r.status_code == 302 and len(glob.glob(os.path.join(os.path.dirname(__file__), '..', 'storage', 'backups', '*.gz'))) == len(bk))
for pth in ['bills.php', 'shift.php', 'kitchen.php', 'backup.php', 'index.php', 'pos.php', 'orders.php', 'reports.php', 'products.php', 'categories.php', 'tables.php', 'users.php', 'settings.php', 'outlets.php', 'activity.php', 'profile.php', 'reports.php?export=csv', 'orders.php?export=csv']:
    so.post(B + 'switch-outlet.php', data={'_csrf': csrf(so, 'index.php'), 'outlet_id': 1})
    r = so.get(B + pth); check('halaman OK: ' + pth, r.status_code == 200 and 'Terjadi kesalahan' not in r.text and 'Fatal' not in r.text and 'Warning' not in r.text, r.status_code)
print(f'\nHASIL: {OK} lulus, {FAIL} gagal')
sys.exit(1 if FAIL else 0)
