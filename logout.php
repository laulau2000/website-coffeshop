<?php
require_once __DIR__ . '/includes/auth.php';

// Logout hanya lewat POST + CSRF (mencegah logout paksa via link/gambar)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(is_logged_in() ? 'index.php' : 'login.php');
}
verify_csrf();
if (is_logged_in()) {
    log_activity('logout', 'Logout');
}
do_logout();
session_start();
session_regenerate_id(true);
set_flash('info', 'Anda telah keluar dari sistem.');
redirect('login.php');
