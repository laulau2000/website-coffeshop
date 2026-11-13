<?php
/**
 * Router untuk PHP built-in server (development/demo):
 *   php -S 0.0.0.0:8080 tools/router.php
 * Memblokir folder privat yang di Apache dilindungi .htaccess.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#(^|/)(config|database|includes|storage|tests|tools|deploy)(/|$)#', $path)
    || preg_match('#/\.#', $path)
    || preg_match('#\.(sql|md|log|ini|lock|sh|bak)$#i', $path)
    || (str_starts_with($path, '/uploads/') && preg_match('#\.(php\d?|phtml|phar)$#i', $path))) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}
return false;
