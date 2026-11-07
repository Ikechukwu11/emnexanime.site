<?php
/**
 * EmnexAnime site configuration.
 * Direct web access to this file is denied by .htaccess / router.php.
 */
declare(strict_types=1);

if (!defined('EMNX')) {
    http_response_code(403);
    exit('Forbidden');
}

$root    = __DIR__;
$keyFile = $root . '/keys.txt';
$apiKey  = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if ($apiKey === '' && getenv('EMNEX_API_KEY')) {
    $apiKey = trim((string) getenv('EMNEX_API_KEY'));
}

return [
    'site_name'    => 'EmnexAnime',
    'tagline'      => 'Anime, without the noise.',
    'api_base'     => rtrim((string) (getenv('EMNEX_API_BASE') ?: 'https://api.emnexanime.site'), '/'),
    'api_key'      => $apiKey,
    'root'         => $root,
    'storage'      => $root . '/storage',
    'sqlite_cache' => $root . '/storage/cache.sqlite',
    'file_cache'   => $root . '/storage/cache',
    'logs'         => $root . '/storage/logs',
    'timezone'     => 'UTC',
    'per_page'     => 24,
    'max_per_page' => 50,
    'pretty_urls'  => true,
    'debug'        => (bool) getenv('EMNEX_DEBUG'),

    // Metadata is cached in SQLite. Sources use the short-lived file cache.
    'ttl' => [
        'home'       => 1800,   // 30 min
        'list'       => 900,    // 15 min
        'search'     => 900,
        'schedule'   => 900,
        'detail'     => 10800,  // 3 h
        'seasons'    => 43200,  // 12 h
        'episodes'   => 21600,  // 6 h
        'characters' => 86400,  // 24 h
        'genres'     => 86400,  // 24 h
        'sources'    => 180,    // signed stream URLs are short-lived
    ],
];
