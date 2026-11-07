<?php
/**
 * EmnexAnime — router for PHP's built-in development server.
 *
 * Usage: php -S 127.0.0.1:8080 router.php
 * This applies the same access rules as .htaccess (Apache), so plain
 * `php -S 127.0.0.1:8080 router.php` is a faithful preview of production.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

/* ---- deny anything .htaccess would deny ---- */
$blocked = (bool) preg_match('#(^|/)(keys\.txt|config\.php|storage|lib|views|probe|logs)(/|$)#i', $path)
    || (bool) preg_match('#\.sqlite(-wal|-shm)?$|\.log$#i', $path)
    || (bool) preg_match('#(^|/)\.[^/]+$#', $path);
if ($blocked) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('403 Forbidden');
}

/* ---- real files under public scope are served directly ---- */
$file = __DIR__ . $path;
if ($path !== '/' && (is_file($file) || is_dir($file))) {
    return false; // let the built-in server stream it
}

/* ---- everything else is the front controller ---- */
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
