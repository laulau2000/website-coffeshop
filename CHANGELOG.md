# Changelog

## 2.1.0
- **Shift & tutup kasir**: buka shift dengan kas awal, kas masuk/keluar, tutup shift dengan hitung selisih, laporan shift cetak, riwayat shift. Pembayaran wajib shift terbuka.
- **Open bill**: simpan pesanan dan bayar nanti, tambah pesanan susulan, pindah meja, gabung bill, **split bill** per item/qty, cetak tagihan.
- **Layar Dapur/Bar (KDS)**: tiket per pesanan & per tambahan, status item Baru/Dimasak/Siap/Diantar, filter dapur/bar, penanda terlambat, bunyi pesanan baru.
- **Tiket dapur** cetak (dipisah Dapur/Bar, tanpa harga).
- **Peran baru "Dapur / Bar"**: hanya bisa membuka layar dapur.
- **QRIS statis**: gambar QRIS toko tampil di layar bayar.
- **Backup**: menu Backup Data (buat/unduh/hapus) + `tools/backup-cli.php` untuk backup otomatis terjadwal.
- Omzet & laporan hanya menghitung transaksi lunas. Migrasi otomatis skema v3.
- Perbaikan: sapaan login & pengalihan per peran, label meja tidak dobel, tata letak mobile halaman bill/shift.

## 2.0.0
### Keamanan (kritis)
- Hapus `fix_login.php` (bisa mereset seluruh database tanpa login).
- Installer sekali pakai: terkunci setelah instal, tidak pernah `DROP TABLE`, password pemilik ditentukan sendiri, CSRF.
- Checkout: harga/nama/varian dari database, pajak & service dari pengaturan, validasi qty, meja & produk harus milik cabang aktif.
- Diskon kasir dibatasi (atur di Pengaturan Toko), di atasnya wajib PIN supervisor + alasan.
- Stok dikunci (`SELECT ... FOR UPDATE`) dan tidak bisa minus; produk "tanpa batas" memakai opsi *Lacak stok* = mati.
- Debug mati, pesan error generik, log di `storage/logs`.
- Akun & password demo dihapus dari halaman login; kebijakan password (min. 8, huruf+angka).
- Cookie sesi HttpOnly/SameSite/Secure, strict mode, timeout idle, rate-limit login, logout POST+CSRF.
- Folder privat diblokir (Apache `.htaccess`, contoh Nginx, router PHP built-in server).
- Validasi isi file upload, eksekusi skrip di `uploads/` dimatikan.
- Cegah open redirect, CSV formula injection, header keamanan.

### Integritas data
- Nomor transaksi atomik per cabang (`order_counters`), anti dobel klik (`client_ref`).
- Harga modal disimpan per item transaksi → laporan laba tidak berubah saat harga modal diubah.
- Pembatalan: hanya admin, wajib alasan, stok dikembalikan, tercatat siapa & kapan.
- Hapus cabang bertransaksi → dinonaktifkan; FK `orders` → `RESTRICT`. Produk & pengguna soft delete.
- Hapus kategori dicek per cabang. Admin tidak bisa mengakses data cabang lain.
- Migrasi skema otomatis dari v1 + indeks tanggal/cabang.

### Fitur & UX
- Service charge dan PB1/PPN dihitung otomatis dan tampil di struk; struk 58/80 mm + logo.
- Status pesanan bermakna: dine-in = *Proses* (meja terisi) → *Selesai* (meja kosong otomatis).
- Tahan pesanan (open bill sederhana) di POS.
- Log Aktivitas (audit trail), halaman Profil (ganti password, PIN supervisor).
- POS mobile: tombol keranjang melayang + laci pesanan; perbaikan overflow horizontal.
- Keranjang tersimpan per kasir & per cabang. Ikon SVG menggantikan emoji. Font sistem (tanpa Google Fonts).
- Pagination & export CSV transaksi. Laporan valid untuk status selesai/proses.
