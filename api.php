<?php
declare(strict_types=1);

/**
 * Internal JSON API for the browser (same origin).
 * Keeps the upstream API key server-side and reuses the PHP caches.
 */
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/** @var array $config */
$api    = new EmnexApi($config);
$action = trim((string) ($_GET['action'] ?? ''));

function ok(mixed $data, array $meta = [], int $maxAge = 60): never
{
    header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=' . ($maxAge * 4));
    json_ok($data, $meta);
}

function fail(string $message, int $status = 400, array $meta = []): never
{
    header('Cache-Control: no-store');
    json_error($message, $status, $meta);
}

try {
    switch ($action) {
        case 'home':
            $res = $api->home();
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 120);
            // no break (never)

        case 'genres':
            $res = $api->genres();
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 300);

        case 'schedule':
            $res = $api->schedule();
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 120);

        case 'feed':
            $type = strtolower(trim((string) ($_GET['type'] ?? 'trending')));
            if (!in_array($type, ['trending', 'popular', 'recent', 'seasonal', 'genre', 'search'], true)) {
                fail('Unknown feed type.', 400);
            }
            $page    = query_int('page', 1, 1, 1000);
            $perPage = query_int('perPage', (int) $config['per_page'], 1, (int) $config['max_per_page']);
            $extra   = [
                'season' => strtoupper(trim((string) ($_GET['season'] ?? ''))),
                'year'   => query_int('year', 0, 0, 3000),
                'genre'  => trim((string) ($_GET['genre'] ?? '')),
                'q'      => trim((string) ($_GET['q'] ?? '')),
            ];
            if ($type === 'search' && $extra['q'] === '') {
                fail('Enter a search term.', 400);
            }
            $res = $api->feed($type, $page, $perPage, $extra);
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 60);

        case 'detail':
            $id  = query_int('id', 0, 1, 100_000_000);
            if ($id <= 0) {
                fail('A valid anime id is required.', 400);
            }
            $res = $api->anime($id);
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 120);

        case 'episodes':
            $id = query_int('id', 0, 1, 100_000_000);
            if ($id <= 0) {
                fail('A valid anime id is required.', 400);
            }
            $res = $api->episodes($id);
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 300);

        case 'characters':
            $id = query_int('id', 0, 1, 100_000_000);
            if ($id <= 0) {
                fail('A valid anime id is required.', 400);
            }
            $res = $api->characters($id);
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 300);

        case 'seasons':
            $id = query_int('id', 0, 1, 100_000_000);
            if ($id <= 0) {
                fail('A valid anime id is required.', 400);
            }
            $res = $api->seasons($id);
            ok($res['data'], ['cache' => $res['cache'], 'age' => $res['age']], 300);

        case 'sources':
            // Sources are signed and short-lived, so never let a browser cache them.
            header('Cache-Control: no-store, max-age=0');            $id      = query_int('id', 0, 1, 100_000_000);
            $ep      = query_int('ep', 1, 0, 99_999);
            $type    = strtolower((string) ($_GET['type'] ?? 'sub')) === 'dub' ? 'dub' : 'sub';
            $kind    = strtolower((string) ($_GET['kind'] ?? 'anilist')) === 'mal' ? 'mal' : 'anilist';
            $malId   = query_int('mal', 0, 0, 100_000_000);
            $refresh = (string) ($_GET['refresh'] ?? '') === '1';

            if ($id <= 0) {
                fail('A valid anime id is required.', 400);
            }

            $res = $api->sources($id, $ep, $type, $kind, $malId > 0 ? $malId : null, $refresh);
            json_ok($res['data'], [
                'cache'    => $res['cache'],
                'age'      => $res['age'],
                'attempts' => $res['attempts'] ?? [],
            ]);

        case 'subtitle': {
            // Same-origin proxy for subtitle tracks so no CORS issues can kill
            // captions. Whitelisted to the upstream API host only (SSRF hard stop).
            $u = (string) ($_GET['u'] ?? '');
            $host = parse_url($u, PHP_URL_HOST) ?: '';
            $allowed = (bool) preg_match('#(^|\.)emnexanime\.site$#i', $host);
            if ($u === '' || $allowed === false) {
                header('Cache-Control: no-store');
                fail('Subtitle URL is missing or not allowed.', 400);
            }
            $client   = new HttpClient('', 15);
            $response = $client->get($u); // HttpClient only speaks JSON; body passthrough
            if ($response['status'] < 200 || $response['status'] >= 300) {
                header('Cache-Control: no-store');
                fail('Subtitle fetch failed upstream.', $response['status'] === 404 ? 404 : 502);
            }
            header('Content-Type: text/vtt; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo $response['body'];
            exit;
        }

        case 'stats':
            $res = $api->cacheStats();
            ok($res, ['cache' => 'BYPASS', 'age' => 0], 0);

        case 'ping':
            ok(['pong' => true, 'key' => $api->hasKey()], ['cache' => 'BYPASS'], 0);

        default:
            fail('Unknown API action.', 404);
    }
} catch (ApiException $e) {
    $status = $e->httpStatus;
    if ($status < 400 || $status > 599) {
        $status = 502;
    }
    fail($e->getMessage(), $status);
} catch (Throwable $e) {
    log_error('Internal API: ' . $e->getMessage());
    fail($config['debug'] ? $e->getMessage() : 'Internal server error.', 500);
}
