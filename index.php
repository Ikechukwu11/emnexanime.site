<?php
declare(strict_types=1);

/**
 * EmnexAnime — front controller.
 */
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: interest-cohort=()');

/** @var array $config */
$api  = new EmnexApi($config);
$path = current_path();

if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

function page(string $view, array $vars, array $options = []): never
{
    $content = render_view($view, $vars);
    render_layout($content, $options);
    exit;
}

function error_page(int $status, string $title, string $message, string $detail = ''): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    page('error', [
        'status'  => $status,
        'title'   => $title,
        'message' => $message,
        'detail'  => $detail,
    ], [
        'title'      => $title,
        'description'=> $message,
        'page'       => 'error',
        'noIndex'    => true,
        'bodyClass'  => 'page-error',
    ]);
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

// ------------------------------------------------------------------- routing
try {
    // Legacy /?p=... fallback (useful when mod_rewrite is unavailable).
    if ($path === '/' && is_string(query('p')) && query('p') !== '') {
        $legacy = (string) query('p');
        $map = [
            'home'      => '/',
            'genres'    => '/genres',
            'schedule'  => '/schedule',
            'search'    => '/search',
            'watchlist' => '/watchlist',
        ];
        if (isset($map[$legacy])) {
            $path = $map[$legacy];
        } elseif ($legacy === 'anime' && query_int('id') > 0) {
            $path = '/anime/' . query_int('id');
        } elseif ($legacy === 'watch' && query_int('id') > 0) {
            $path = '/watch/' . query_int('id') . '/' . max(1, query_int('ep', 1));
        } elseif ($legacy === 'genre' && is_string(query('g'))) {
            $path = '/genre/' . rawurlencode((string) query('g'));
        } elseif ($legacy === 'browse' && is_string(query('type'))) {
            $path = '/browse/' . preg_replace('/[^a-z-]/', '', (string) query('type'));
        }
    }

    // A tiny health endpoint doubles as cache diagnostics.
    if ($path === '/api/stats') {
        header('Cache-Control: no-store');
        json_ok($api->cacheStats());
    }

    if ($path === '/') {
        $res  = $api->home();
        cache_meta_headers($res);
        $home = $res['data'];

        $spotlight = array_values(array_filter((array) ($home['spotlight'] ?? []), 'is_array'));
        $withBanner = array_values(array_filter($spotlight, static fn (array $a): bool => !empty($a['banner'])));
        $withoutBanner = array_values(array_filter($spotlight, static fn (array $a): bool => empty($a['banner'])));
        $hero = array_slice(array_merge($withBanner, $withoutBanner), 0, 6);

        $sections = [];
        $sectionLinks = [
            'trending'  => browse_link('trending'),
            'seasonal'  => browse_link('seasonal'),
            'popular'   => browse_link('popular'),
            'topRated'  => browse_link('top-rated'),
            'upcoming'  => browse_link('upcoming'),
        ];
        foreach ((array) ($home['sections'] ?? []) as $section) {
            if (!is_array($section) || empty($section['items']) || !is_array($section['items'])) {
                continue;
            }
            $sections[] = [
                'key'   => (string) ($section['key'] ?? ''),
                'title' => (string) ($section['title'] ?? 'More anime'),
                'items' => array_slice(array_values(array_filter($section['items'], 'is_array')), 0, 18),
                'href'  => $sectionLinks[(string) ($section['key'] ?? '')] ?? null,
            ];
        }

        header('Cache-Control: public, max-age=60, stale-while-revalidate=240');
        page('home', [
            'hero'     => $hero,
            'sections' => $sections,
        ], [
            'title'       => $config['site_name'] . ' — stream anime without the noise',
            'description' => 'Browse trending, seasonal and popular anime, then stream episodes ad-free with subtitles, skip-intro and resume support.',
            'image'       => $hero ? banner_of($hero[0]) : null,
            'canonical'   => absolute_url('/'),
            'page'        => 'home',
            'bodyClass'   => 'page-home',
            'scripts'     => ['home.js'],
        ]);
    }

    if ($path === '/random') {
        $res  = $api->home();
        $pool = array_values(array_filter((array) ($res['data']['spotlight'] ?? []), 'is_array'));
        if (!$pool) {
            $feed = $api->feed('trending', 1, 20);
            $pool = array_values(array_filter((array) ($feed['data']['items'] ?? []), 'is_array'));
        }
        if (!$pool) {
            redirect('/');
        }
        $pick = $pool[array_rand($pool)];
        redirect('anime/' . (int) $pick['id']);
    }

    if (preg_match('#^/anime/(\d+)$#', $path, $m)) {
        $id   = (int) $m[1];
        $res  = $api->anime($id);
        cache_meta_headers($res);
        $anime = $res['data'];
        if (empty($anime['id'])) {
            error_page(404, 'Title not found', 'That anime does not exist or is not available through the API.');
        }

        $desc  = excerpt_text(clean_description($anime['description'] ?? ''), 180);
        $image = banner_of($anime);
        $canonical = absolute_url('anime/' . $id);
        $jsonLd = [
            '@context'    => 'https://schema.org',
            '@type'       => 'TVSeries',
            'name'        => title_of($anime),
            'description' => $desc,
            'image'       => poster_of($anime),
            'url'         => $canonical,
            'genre'       => array_values((array) ($anime['genres'] ?? [])),
            'numberOfEpisodes' => $anime['episodes'] ?? null,
            'aggregateRating'  => is_numeric($anime['rating'] ?? null) ? [
                '@type'       => 'AggregateRating',
                'ratingValue' => (float) $anime['rating'],
                'bestRating'  => 10,
                'ratingCount' => max(1, (int) ($anime['popularity'] ?? 1)),
            ] : null,
        ];
        if (is_numeric($anime['year'] ?? null)) {
            $jsonLd['startDate'] = (string) (int) $anime['year'];
        }

        header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
        page('anime', [
            'anime'    => $anime,
            'apiMeta'  => $res,
        ], [
            'title'       => title_of($anime) . ' — ' . $config['site_name'],
            'description' => $desc !== '' ? $desc : 'Watch ' . title_of($anime) . ' online.',
            'image'       => $image,
            'canonical'   => $canonical,
            'page'        => 'anime',
            'bodyClass'   => 'page-anime',
            'scripts'     => ['anime.js'],
            'jsonLd'      => $jsonLd,
            'ogType'      => 'video.tv_show',
        ]);
    }

    if (preg_match('#^/watch/(\d+)/(\d+)$#', $path, $m)) {
        $id      = (int) $m[1];
        $episode = max(0, (int) $m[2]);
        // Dub is the default audio track; ?type=sub opts back into subs.
        $type    = query('type') === 'sub' ? 'sub' : 'dub';

        $res   = $api->anime($id);
        $anime = $res['data'];
        if (empty($anime['id'])) {
            error_page(404, 'Title not found', 'That anime does not exist or is not available through the API.');
        }
        $total = (int) ($anime['episodes'] ?? 0);
        $hasNext = $total <= 0 || $episode < $total;

        header('Cache-Control: private, no-store');
        page('watch', [
            'anime'   => $anime,
            'episode' => $episode,
            'type'    => $type,
            'hasNext' => $hasNext,
        ], [
            'title'       => 'Watch ' . title_of($anime) . ' — Episode ' . $episode . ' · ' . $config['site_name'],
            'description' => 'Stream ' . title_of($anime) . ' episode ' . $episode . ' with subtitles and skip-intro.',
            'image'       => banner_of($anime),
            'canonical'   => absolute_url('watch/' . $id . '/' . $episode),
            'page'        => 'watch',
            'bodyClass'   => 'page-watch',
            'scripts'     => ['watch.js'],
            'noIndex'     => true,
        ]);
    }

    if ($path === '/browse' || $path === '/browse/') {
        redirect('browse/trending');
    }

    if (preg_match('#^/browse/([a-z-]+)$#', $path, $m)) {
        $type = $m[1];
        $defs = [
            'trending'  => ['feed' => 'trending', 'title' => 'Trending now', 'subtitle' => 'The titles pulling the most attention right now.'],
            'popular'   => ['feed' => 'popular', 'title' => 'All-time popular', 'subtitle' => 'The biggest anime ever, ranked by popularity.'],
            'recent'    => ['feed' => 'recent', 'title' => 'New episodes', 'subtitle' => 'Currently airing shows with fresh episodes.'],
            'seasonal'  => ['feed' => 'seasonal', 'title' => 'This season', 'subtitle' => 'Airing this season, straight from the current chart.'],
            'top-rated' => ['feed' => 'home:topRated', 'title' => 'Top rated', 'subtitle' => 'The highest-scored picks in the current home feed.'],
            'upcoming'  => ['feed' => 'home:upcoming', 'title' => 'Upcoming anime', 'subtitle' => 'Not out yet — add them to your watchlist before they air.'],
        ];
        if (!isset($defs[$type])) {
            error_page(404, 'Page not found', 'That browse collection does not exist.');
        }

        $def = $defs[$type];
        if (str_starts_with($def['feed'], 'home:')) {
            $home  = $api->home();
            $key   = substr($def['feed'], 5);
            $items = [];
            foreach ((array) ($home['data']['sections'] ?? []) as $section) {
                if (($section['key'] ?? '') === $key) {
                    $items = array_values(array_filter((array) ($section['items'] ?? []), 'is_array'));
                    break;
                }
            }
            header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
            page('browse', [
                'feedType'   => 'home:' . $key,
                'items'      => $items,
                'pageInfo'   => ['hasNextPage' => false],
                'title'      => $def['title'],
                'subtitle'   => $def['subtitle'],
                'filters'    => '',
                'emptyText'  => 'Nothing in this collection right now.',
                'perPage'    => count($items),
                'params'     => [],
                'apiMeta'    => $home,
                'searchForm' => false,
            ], [
                'title'       => $def['title'] . ' — ' . $config['site_name'],
                'description' => $def['subtitle'],
                'canonical'   => absolute_url('browse/' . $type),
                'page'        => 'browse',
                'bodyClass'   => 'page-browse',
                'scripts'     => ['browse.js'],
            ]);
        }

        $extra = [];
        $filters = '';
        if ($type === 'seasonal') {
            [$currentSeason, $currentYear] = current_season();
            $season = strtoupper((string) (query('season') ?: $currentSeason));
            $year   = query_int('year', $currentYear, 1940, 2100);
            if (!in_array($season, ['WINTER', 'SPRING', 'SUMMER', 'FALL'], true)) {
                $season = $currentSeason;
            }
            $extra = ['season' => $season, 'year' => $year];
            $def['title'] = season_label($season) . ' ' . $year . ' anime';
            $def['subtitle'] = 'The season chart for ' . season_label($season) . ' ' . $year . '.';
            $seasonOptions = '';
            foreach (['WINTER', 'SPRING', 'SUMMER', 'FALL'] as $s) {
                $seasonOptions .= '<option value="' . $s . '"' . ($s === $season ? ' selected' : '') . '>' . season_label($s) . '</option>';
            }
            $yearOptions = '';
            for ($y = $currentYear + 1; $y >= 1990; $y--) {
                $yearOptions .= '<option value="' . $y . '"' . ($y === $year ? ' selected' : '') . '>' . $y . '</option>';
            }
            $filters = '<form class="filters" method="get" action="' . e(url('browse/seasonal')) . '">'
                . '<label class="field"><span>Season</span><select name="season">' . $seasonOptions . '</select></label>'
                . '<label class="field"><span>Year</span><select name="year">' . $yearOptions . '</select></label>'
                . '<button class="btn btn--primary btn--sm" type="submit">Apply</button>'
                . '</form>';
        }

        $res  = $api->feed($def['feed'], 1, (int) $config['per_page'], $extra);
        cache_meta_headers($res);
        $items    = array_values(array_filter((array) ($res['data']['items'] ?? []), 'is_array'));
        $pageInfo = (array) ($res['data']['pageInfo'] ?? []);

        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        page('browse', [
            'feedType'  => $def['feed'],
            'items'     => $items,
            'pageInfo'  => $pageInfo,
            'title'     => $def['title'],
            'subtitle'  => $def['subtitle'],
            'filters'   => $filters,
            'emptyText' => 'No anime found here yet.',
            'perPage'   => (int) $config['per_page'],
            'params'    => $extra,
            'apiMeta'   => $res,
            'searchForm'=> false,
        ], [
            'title'       => $def['title'] . ' — ' . $config['site_name'],
            'description' => $def['subtitle'],
            'canonical'   => absolute_url('browse/' . $type),
            'page'        => 'browse',
            'bodyClass'   => 'page-browse',
            'scripts'     => ['browse.js'],
        ]);
    }

    if (preg_match('#^/genre/(.+)$#', $path, $m)) {
        $genre = rawurldecode($m[1]);
        $genre = trim(preg_replace('/\s+/', ' ', $genre) ?? $genre);
        if ($genre === '' || mb_strlen($genre) > 40) {
            error_page(404, 'Genre not found', 'That genre does not exist.');
        }
        $res   = $api->feed('genre', 1, (int) $config['per_page'], ['genre' => $genre]);
        cache_meta_headers($res);
        $items = array_values(array_filter((array) ($res['data']['items'] ?? []), 'is_array'));
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        page('browse', [
            'feedType'  => 'genre',
            'items'     => $items,
            'pageInfo'  => (array) ($res['data']['pageInfo'] ?? []),
            'title'     => $genre . ' anime',
            'subtitle'  => 'Popular ' . strtolower($genre) . ' titles, ranked by popularity.',
            'filters'   => '',
            'emptyText' => 'No titles in this genre yet.',
            'perPage'   => (int) $config['per_page'],
            'params'    => ['genre' => $genre],
            'apiMeta'   => $res,
            'searchForm'=> false,
            'backLink'  => url('genres'),
            'backLabel' => 'All genres',
        ], [
            'title'       => $genre . ' anime — ' . $config['site_name'],
            'description' => 'Browse popular ' . $genre . ' anime.',
            'canonical'   => absolute_url('genre/' . rawurlencode($genre)),
            'page'        => 'browse',
            'bodyClass'   => 'page-browse',
            'scripts'     => ['browse.js'],
        ]);
    }

    if ($path === '/genres') {
        $res = $api->genres();
        cache_meta_headers($res);
        header('Cache-Control: public, max-age=300, stale-while-revalidate=900');
        page('genres', [
            'genres' => array_values((array) ($res['data']['genres'] ?? [])),
        ], [
            'title'       => 'Genres — ' . $config['site_name'],
            'description' => 'Browse anime by genre: action, romance, comedy, fantasy, sci-fi and more.',
            'canonical'   => absolute_url('genres'),
            'page'        => 'genres',
            'bodyClass'   => 'page-genres',
        ]);
    }

    if ($path === '/schedule') {
        $res = $api->schedule();
        cache_meta_headers($res);
        header('Cache-Control: public, max-age=120, stale-while-revalidate=300');
        page('schedule', [
            'schedule' => array_values(array_filter((array) ($res['data']['schedule'] ?? []), 'is_array')),
        ], [
            'title'       => 'Airing schedule — ' . $config['site_name'],
            'description' => 'See which anime episodes air over the next seven days, in your local time.',
            'canonical'   => absolute_url('schedule'),
            'page'        => 'schedule',
            'bodyClass'   => 'page-schedule',
            'scripts'     => ['schedule.js'],
        ]);
    }

    if ($path === '/search') {
        $q = trim((string) query('q', ''));
        $q = mb_substr($q, 0, 80);

        if ($q === '') {
            $genresRes = $api->genres();
            $trendRes  = $api->feed('trending', 1, 18);
            page('search', [
                'q'        => '',
                'result'   => null,
                'genres'   => array_values((array) ($genresRes['data']['genres'] ?? [])),
                'trending' => array_values(array_filter((array) ($trendRes['data']['items'] ?? []), 'is_array')),
            ], [
                'title'       => 'Search — ' . $config['site_name'],
                'description' => 'Search thousands of anime titles by name.',
                'canonical'   => absolute_url('search'),
                'page'        => 'search',
                'bodyClass'   => 'page-search',
                'scripts'     => ['browse.js', 'search.js'],
                'searchValue' => '',
            ]);
        }

        $res   = $api->feed('search', 1, 50, ['q' => $q]);
        cache_meta_headers($res);
        $items = array_values(array_filter((array) ($res['data']['items'] ?? []), 'is_array'));
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        page('search', [
            'q'        => $q,
            'result'   => [
                'items'    => $items,
                'pageInfo' => (array) ($res['data']['pageInfo'] ?? []),
            ],
            'genres'   => [],
            'trending' => [],
        ], [
            'title'       => 'Search “' . $q . '” — ' . $config['site_name'],
            'description' => 'Search results for ' . $q . ' on ' . $config['site_name'] . '.',
            'canonical'   => absolute_url('search?q=' . rawurlencode($q)),
            'page'        => 'search',
            'bodyClass'   => 'page-search',
            'scripts'     => ['browse.js', 'search.js'],
            'searchValue' => $q,
        ]);
    }

    if ($path === '/watchlist') {
        header('Cache-Control: no-store');
        page('watchlist', [], [
            'title'       => 'My watchlist — ' . $config['site_name'],
            'description' => 'Your saved anime, stored locally in this browser.',
            'page'        => 'watchlist',
            'bodyClass'   => 'page-watchlist',
            'scripts'     => ['watchlist.js'],
            'noIndex'     => true,
        ]);
    }

    if (preg_match('#^/anime/(\d+)/watch/(\d+)$#', $path, $m)) {
        redirect('watch/' . (int) $m[1] . '/' . max(1, (int) $m[2]));
    }

    error_page(404, 'Page not found', 'The page you are looking for drifted off the map.');
} catch (ApiException $e) {
    $status = $e->httpStatus;
    if ($status === 401 || $status === 403) {
        $status = 502;
    }
    if ($status < 400 || $status > 599) {
        $status = 502;
    }
    $friendly = match ($status) {
        404 => ['Not found', 'We could not find that title or episode.'],
        429 => ['Slow down a moment', 'The upstream API rate limit was reached. The local cache usually has you covered — try again in a few seconds.'],
        400 => ['Bad request', 'Something in the request was not valid.'],
        default => ['The stream is taking a break', 'We could not reach the anime service. Check the API key and your connection, then try again.'],
    };
    log_error('ApiException [' . $status . ']: ' . $e->getMessage());
    error_page($status, $friendly[0], $friendly[1], $e->getMessage());
} catch (Throwable $e) {
    log_error('Unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_page(500, 'Something went wrong', 'An unexpected error occurred. The details were written to the server log.', $config['debug'] ? $e->getMessage() : '');
}
