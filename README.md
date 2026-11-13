# KasirKu POS v2.1 — POS Restoran & Coffee Shop

Aplikasi kasir berbasis web (PHP + MySQL/MariaDB) untuk restoran, kafe, dan coffee shop multi-cabang.

## Kebutuhan server
- PHP 8.0+ dengan ekstensi `pdo_mysql`, `mbstring`, `gd` (atau `fileinfo`)
- MySQL 5.7+ / MariaDB 10.3+
- Apache (dengan `mod_rewrite`, `.htaccess` sudah disertakan) **atau** Nginx (lihat `deploy/nginx.conf.example`)
- HTTPS sangat disarankan untuk produksi (cookie sesi otomatis `Secure` bila HTTPS)

## Instalasi baru
1. Upload semua file ke folder web (mis. `public_html/pos`).
2. Salin `config/config.local.example.php` menjadi `config/config.local.php`, isi kredensial database.
   Nama aplikasi/tagline untuk white-label juga bisa diatur di sini (`APP_NAME`, `APP_TAGLINE`).
3. Pastikan folder `storage/` dan `uploads/` bisa ditulis oleh web server (`chmod 775`).
4. Buka `https://domain-anda/pos/install.php`, isi nama usaha, akun pemilik, dan password (min. 8 karakter, huruf + angka).
   Centang "data contoh" bila ingin demo menu/meja.
5. Selesai. Installer terkunci otomatis (`storage/installed.lock`) dan tidak bisa dijalankan ulang di database yang sudah berisi data.
6. Masuk sebagai pemilik → **Pengguna** untuk membuat admin & kasir; **Profil & Password** untuk mengatur PIN supervisor.

## Upgrade dari v1
1. **Backup database** terlebih dahulu.
2. Timpa file aplikasi dengan versi ini (jangan hapus folder `uploads/`). Hapus `fix_login.php` bila masih ada.
3. Buat `config/config.local.php` berisi kredensial database lama Anda.
4. Buat file kosong `storage/installed.lock`.
5. Buka aplikasi seperti biasa — skema database di-upgrade otomatis (kolom baru, indeks, tabel audit) tanpa menghapus data.
6. Password lama `admin123` tetap bisa dipakai login, tetapi aplikasi akan menampilkan peringatan sampai password diganti.

## Peran pengguna
| Peran | Akses |
|---|---|
| Superadmin (pemilik) | Semua cabang, cabang/outlet, pengguna, laporan, log aktivitas |
| Admin | Cabangnya sendiri: menu, kategori, meja, pengguna, laporan, pembatalan transaksi, PIN supervisor |
| Kasir | POS, open bill, shift, transaksi, meja; diskon dibatasi (default 10%), tidak melihat harga modal |
| Dapur / Bar | Hanya Layar Dapur (KDS) dan profil |

## Alur kerja harian
1. **Buka shift** (menu Shift Kasir) dengan kas awal. Pembayaran hanya bisa diproses saat shift terbuka.
2. **Kasir / POS**: pilih menu → *Bayar Sekarang* (bayar di depan) atau *Simpan Bill* (open bill, bayar nanti).
3. **Open Bill**: tambah pesanan, pindah meja, gabung bill, bayar sebagian (split bill) atau lunasi.
4. **Layar Dapur / Bar**: pesanan masuk otomatis (refresh 5 detik + bunyi). Ketuk item: Baru → Dimasak → Siap → Diantar.
   Item kategori *kopi/minuman* masuk ke Bar, lainnya ke Dapur. Tiket dapur juga bisa dicetak dari halaman bill/transaksi.
5. **Tutup shift**: hitung uang di laci, sistem menghitung kas seharusnya dan selisihnya. Laporan shift bisa dicetak.
6. **QRIS**: unggah gambar QRIS toko di Pengaturan Toko; gambar tampil di layar bayar saat metode QRIS dipilih.

## Fitur keamanan & kontrol
- Harga, varian, pajak, service charge dan stok dihitung ulang di server; data harga dari browser diabaikan.
- Diskon di atas batas kasir wajib PIN supervisor + alasan; semua diskon tercatat di **Log Aktivitas**.
- Stok dicek dan dikunci saat checkout (tidak bisa oversell), dikembalikan otomatis saat transaksi dibatalkan.
- Pembatalan transaksi hanya admin/pemilik dan wajib alasan.
- Nomor transaksi atomik per cabang per hari (`PST-261113-0001`), aman untuk banyak kasir sekaligus.
- Batas percobaan login (5x / 15 menit), timeout sesi 8 jam, cookie HttpOnly + SameSite, logout via POST.
- Ganti/reset password otomatis mengeluarkan sesi lain akun tersebut.
- Upload gambar diverifikasi isinya; folder `uploads/` tidak bisa menjalankan skrip.
- Mode debug mati secara default; error dicatat di `storage/logs/` tanpa menampilkan detail ke pengguna.
- Produk, pengguna, dan cabang yang sudah punya transaksi tidak dihapus permanen (soft delete / nonaktif) sehingga laporan tetap utuh.

## Backup
- Menu **Backup Data** (superadmin): buat & unduh backup `.sql.gz` kapan saja.
- Backup otomatis harian (menyimpan 14 file terakhir di `storage/backups/`):
  - Linux: `0 2 * * * php /path/kasirku/tools/backup-cli.php`
  - Windows/XAMPP (Task Scheduler): `C:\xampp\php\php.exe C:\xampp\htdocs\kasirku\tools\backup-cli.php`
- Restore: impor file backup lewat phpMyAdmin ke database kosong.
- Simpan salinan backup di luar server (Google Drive / flashdisk).

## Pengujian
`tests/regression_test.py` menguji 96 skenario keamanan & integritas pada instalasi kosong:
```
php -S 127.0.0.1:8080 tools/router.php
python3 tests/regression_test.py http://127.0.0.1:8080/
```
