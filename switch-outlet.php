<?php
require_once __DIR__ . '/includes/auth.php';
require_role('superadmin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $oid = (int)post('outlet_id', 0);
    if ($oid > 0 && fetchOne('SELECT id FROM outlets WHERE id = ? AND aktif = 1', [$oid])) {
        $_SESSION['outlet_aktif'] = $oid;
    } else {
        unset($_SESSION['outlet_aktif']);
    }
}
$back = (string)post('redirect', '');
if ($back === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = parse_url($_SERVER['HTTP_REFERER']);
    if (($ref['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '') || ($ref['host'] ?? '') === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        $back = ($ref['path'] ?? '') . (isset($ref['query']) ? '?' . $ref['query'] : '');
    }
}
redirect(safe_internal_path($back));
