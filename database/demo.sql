-- =====================================================================
--  DATA CONTOH (opsional) - dijalankan installer bila "Sertakan data contoh" dicentang
-- =====================================================================
INSERT INTO `outlets` (`id`,`kode`,`nama`,`jenis`,`alamat`,`telepon`,`aktif`) VALUES
(1,'PST','KasirKu Coffee & Resto - Pusat','hybrid','Jl. Merdeka No. 10, Jakarta','021-5550001',1),
(2,'CBG','KasirKu Cabang Bandung','coffee_shop','Jl. Asia Afrika No. 20, Bandung','022-5550002',1);

INSERT INTO `settings` (`outlet_id`,`nama_resto`,`alamat`,`telepon`,`pajak_persen`,`service_persen`,`footer_struk`) VALUES
(1,'KasirKu Coffee & Resto','Jl. Merdeka No. 10, Jakarta','021-5550001',10,0,'Terima kasih atas kunjungan Anda'),
(2,'KasirKu Cabang Bandung','Jl. Asia Afrika No. 20, Bandung','022-5550002',10,5,'Terima kasih atas kunjungan Anda');

INSERT INTO `categories` (`id`,`outlet_id`,`nama`,`tipe`,`urutan`) VALUES
(1,1,'Kopi','kopi',1),
(2,1,'Non-Kopi','minuman',2),
(3,1,'Makanan Utama','makanan',3),
(4,1,'Snack & Gorengan','snack',4),
(5,1,'Dessert','dessert',5),
(6,2,'Signature Coffee','kopi',1),
(7,2,'Pastry','snack',2);

INSERT INTO `products` (`outlet_id`,`category_id`,`nama`,`sku`,`harga`,`harga_modal`,`lacak_stok`,`stok`,`satuan`,`deskripsi`,`varian`,`favorit`,`aktif`) VALUES
(1,1,'Espresso','KP-001',18000,6000,0,999,'cup','Single shot espresso','[{"nama":"Size","opsi":[{"label":"Single","tambahan":0},{"label":"Double","tambahan":5000}]}]',1,1),
(1,1,'Americano','KP-002',22000,7000,0,999,'cup','Espresso + air panas','[{"nama":"Suhu","opsi":[{"label":"Hot","tambahan":0},{"label":"Ice","tambahan":3000}]},{"nama":"Size","opsi":[{"label":"Reguler","tambahan":0},{"label":"Large","tambahan":5000}]}]',1,1),
(1,1,'Cafe Latte','KP-003',28000,9000,0,999,'cup','Espresso + susu steamed','[{"nama":"Suhu","opsi":[{"label":"Hot","tambahan":0},{"label":"Ice","tambahan":3000}]}]',1,1),
(1,1,'Cappuccino','KP-004',28000,9000,0,999,'cup','Espresso + foam susu','[{"nama":"Suhu","opsi":[{"label":"Hot","tambahan":0},{"label":"Ice","tambahan":3000}]}]',0,1),
(1,2,'Matcha Latte','MN-001',30000,11000,0,999,'cup','Matcha premium + susu','[{"nama":"Suhu","opsi":[{"label":"Hot","tambahan":0},{"label":"Ice","tambahan":3000}]}]',1,1),
(1,2,'Chocolate','MN-002',26000,8000,0,999,'cup','Cokelat klasik','[{"nama":"Suhu","opsi":[{"label":"Hot","tambahan":0},{"label":"Ice","tambahan":3000}]}]',0,1),
(1,2,'Es Teh Manis','MN-003',10000,2500,0,999,'gelas','Teh manis dingin',NULL,0,1),
(1,3,'Nasi Goreng Spesial','MK-001',35000,15000,1,50,'porsi','Nasi goreng + telur + ayam','[{"nama":"Level Pedas","opsi":[{"label":"Tidak Pedas","tambahan":0},{"label":"Pedas","tambahan":0},{"label":"Extra Pedas","tambahan":0}]}]',1,1),
(1,3,'Mie Goreng Jawa','MK-002',30000,12000,1,50,'porsi','Mie goreng bumbu jawa',NULL,0,1),
(1,3,'Ayam Geprek','MK-003',32000,14000,1,50,'porsi','Ayam crispy + sambal geprek','[{"nama":"Level Pedas","opsi":[{"label":"Level 1","tambahan":0},{"label":"Level 3","tambahan":0},{"label":"Level 5","tambahan":2000}]}]',1,1),
(1,4,'French Fries','SN-001',20000,7000,1,100,'porsi','Kentang goreng','[{"nama":"Saus","opsi":[{"label":"Mayo","tambahan":0},{"label":"Keju","tambahan":5000},{"label":"BBQ","tambahan":5000}]}]',0,1),
(1,4,'Pisang Goreng Keju','SN-002',18000,6000,1,100,'porsi','Pisang goreng + keju susu',NULL,0,1),
(1,5,'Cheesecake','DS-001',32000,14000,1,20,'slice','New York cheesecake',NULL,1,1),
(1,5,'Brownies Ice Cream','DS-002',30000,12000,1,20,'porsi','Brownies hangat + es krim',NULL,0,1),
(2,6,'Kopi Susu Gula Aren','CB-001',24000,8000,0,999,'cup','Signature kopi susu gula aren','[{"nama":"Suhu","opsi":[{"label":"Ice","tambahan":0},{"label":"Hot","tambahan":0}]}]',1,1),
(2,6,'Latte Bandung','CB-002',27000,9000,0,999,'cup','Latte dengan gula aren',NULL,0,1),
(2,7,'Croissant Butter','PS-001',22000,9000,1,30,'pcs','Croissant mentega',NULL,1,1),
(2,7,'Cinnamon Roll','PS-002',24000,10000,1,30,'pcs','Cinnamon roll homemade',NULL,0,1);

INSERT INTO `resto_tables` (`outlet_id`,`nama`,`area`,`kapasitas`,`status`) VALUES
(1,'Meja 01','Indoor',2,'kosong'),
(1,'Meja 02','Indoor',2,'kosong'),
(1,'Meja 03','Indoor',4,'kosong'),
(1,'Meja 04','Indoor',4,'kosong'),
(1,'Meja 05','Outdoor',4,'kosong'),
(1,'Meja 06','Outdoor',6,'kosong'),
(1,'VIP 01','VIP Room',8,'kosong'),
(2,'Bar 01','Bar',2,'kosong'),
(2,'Bar 02','Bar',2,'kosong'),
(2,'Meja 01','Indoor',4,'kosong');
