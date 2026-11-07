<?php
/**
 * Front controller bootstrap: config, helpers, autoloader, error handling.
 */
declare(strict_types=1);

define('EMNX', true);
define('EMNX_ROOT', dirname(__DIR__));
define('EMNX_START', microtime(true));

$config = require EMNX_ROOT . '/config.php';

// ---------------------------------------------------------------- environment
date_default_timezone_set($config['timezone'] ?? 'UTC');
mb_internal_encoding('UTF-8');

if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

// The API key must never leak. keys.txt, storage and config are denied in .htaccess.
if (!is_dir($config['storage'])) {
    @mkdir($config['storage'], 0775, true);
}
if (!is_dir($config['file_cache'])) {
    @mkdir($config['file_cache'], 0775, true);
}
if (!is_dir($config['logs'])) {
    @mkdir($config['logs'], 0775, true);
}

// ------------------------------------------------------------------ autoload
spl_autoload_register(static function (string $class): void {
    $file = EMNX_ROOT . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ----------------------------------------------------------------- base path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath   = str_replace('\\', '/', dirname($scriptName));
if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
    $basePath = '';
}
$basePath = rtrim($basePath, '/');
define('BASE_PATH', $basePath);

// --------------------------------------------------------------- tiny helpers
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_PATH . '/' . ltrim($path, '/');
}

function absolute_url(string $path = ''): string
{
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = EMNX_ROOT . '/assets/' . $path;
    $v    = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . $path) . '?v=' . $v;
}

function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (BASE_PATH !== '' && str_starts_with($uri, BASE_PATH)) {
        $uri = substr($uri, strlen(BASE_PATH));
    }
    $uri = '/' . trim($uri, '/');
    if ($uri === '/index.php' || $uri === '/index.php/') {
        $uri = '/';
    }
    return $uri;
}

function query(string $key, mixed $default = null): mixed
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function query_int(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    $raw = query($key);
    if ($raw === null || $raw === '' || !is_string($raw) || !preg_match('/^-?\d+$/', $raw)) {
        return $default;
    }
    return max($min, min($max, (int) $raw));
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 302);
    exit;
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_ok(mixed $data, array $meta = []): never
{
    json_out(['ok' => true, 'data' => $data, 'meta' => $meta]);
}

function json_error(string $message, int $status = 400, array $meta = []): never
{
    json_out(['ok' => false, 'error' => $message, 'meta' => $meta], $status);
}

function json_for_script(mixed $data): string
{
    return (string) json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

function log_error(string $message): void
{
    global $config;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(($config['logs'] ?? (EMNX_ROOT . '/storage/logs')) . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function render_view(string $name, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include EMNX_ROOT . '/views/' . $name . '.php';
    return (string) ob_get_clean();
}

function render_layout(string $content, array $options = []): void
{
    extract($options, EXTR_SKIP);
    include EMNX_ROOT . '/views/layout.php';
}

// ------------------------------------------------------------- data utilities
function title_of(array $anime): string
{
    $titles = $anime['titles'] ?? [];
    foreach (['english', 'romaji', 'native'] as $key) {
        $value = trim((string) ($titles[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    $title = trim((string) ($anime['title'] ?? ''));
    return $title !== '' ? $title : 'Untitled';
}

function alt_title_of(array $anime): string
{
    $titles  = $anime['titles'] ?? [];
    $english = trim((string) ($titles['english'] ?? ''));
    $romaji  = trim((string) ($titles['romaji'] ?? ''));
    $native  = trim((string) ($titles['native'] ?? ''));
    $main    = title_of($anime);
    foreach ([$romaji, $english, $native] as $candidate) {
        if ($candidate !== '' && strcasecmp($candidate, $main) !== 0) {
            return $candidate;
        }
    }
    return '';
}

function poster_of(array $anime): string
{
    $poster = trim((string) ($anime['poster'] ?? ''));
    return $poster !== '' ? $poster : url('assets/img/placeholder-poster.svg');
}

function banner_of(array $anime): string
{
    $banner = trim((string) ($anime['banner'] ?? ''));
    if ($banner !== '') {
        return $banner;
    }
    return poster_of($anime);
}

function accent_of(array $anime): string
{
    $color = (string) ($anime['color'] ?? '');
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#39d353';
}

function initials_of(string $title): string
{
    $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title) ?? $title;
    $words = preg_split('/\s+/u', trim($clean)) ?: [];
    $out   = '';
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $out .= mb_strtoupper(mb_substr($word, 0, 1));
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : 'EA';
}

function rating_of(array $anime): string
{
    $rating = $anime['rating'] ?? null;
    return is_numeric($rating) ? number_format((float) $rating, 1) : '—';
}

function year_of(array $anime): string
{
    $year = $anime['year'] ?? null;
    return is_numeric($year) ? (string) $year : '—';
}

function format_label(?string $format): string
{
    return match (strtoupper((string) $format)) {
        'TV'        => 'TV',
        'TV_SHORT'  => 'TV Short',
        'MOVIE'     => 'Movie',
        'OVA'       => 'OVA',
        'ONA'       => 'ONA',
        'SPECIAL'   => 'Special',
        'MUSIC'     => 'Music',
        default     => $format ? ucfirst(strtolower((string) $format)) : '—',
    };
}

function status_label(?string $status): string
{
    return match (strtoupper((string) $status)) {
        'RELEASING'        => 'Airing',
        'FINISHED'         => 'Finished',
        'NOT_YET_RELEASED' => 'Upcoming',
        'CANCELLED'        => 'Cancelled',
        'HIATUS'           => 'Hiatus',
        default            => $status ? ucfirst(strtolower((string) $status)) : '—',
    };
}

function season_label(?string $season): string
{
    return match (strtoupper((string) $season)) {
        'WINTER' => 'Winter',
        'SPRING' => 'Spring',
        'SUMMER' => 'Summer',
        'FALL'   => 'Fall',
        default  => $season ? ucfirst(strtolower((string) $season)) : '—',
    };
}

function episodes_label(array $anime): string
{
    $eps = $anime['episodes'] ?? null;
    return is_numeric($eps) && (int) $eps > 0 ? ((int) $eps) . ' eps' : '? eps';
}

function human_count(int|float|null $number): string
{
    if ($number === null) {
        return '—';
    }
    $n = (float) $number;
    if ($n >= 1_000_000) {
        return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1_000) {
        return rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.') . 'K';
    }
    return (string) (int) $n;
}

function clean_description(?string $html): string
{
    $text = (string) $html;
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function excerpt_text(string $text, int $limit = 260): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    $cut = mb_substr($text, 0, $limit);
    $pos = mb_strrpos($cut, ' ');
    if ($pos !== false) {
        $cut = mb_substr($cut, 0, $pos);
    }
    return rtrim($cut, " \t\n.,;:") . '…';
}

function description_html(?string $html): string
{
    $text = clean_description($html);
    if ($text === '') {
        return '<p class="muted">No synopsis is available for this title yet.</p>';
    }
    $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
    $out = '';
    foreach ($paragraphs as $paragraph) {
        $out .= '<p>' . nl2br(e(trim($paragraph)), false) . '</p>';
    }
    return $out;
}

function duration_label(?int $minutes): string
{
    if (!$minutes || $minutes <= 0) {
        return '—';
    }
    return $minutes . 'm';
}

function youtube_id(?array $trailer): string
{
    if (!$trailer) {
        return '';
    }
    $id = trim((string) ($trailer['youtube'] ?? ''));
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
    return $id;
}

function seconds_hms(float|int $seconds): string
{
    $seconds = max(0, (int) $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function current_season(): array
{
    $month = (int) date('n');
    $year  = (int) date('Y');
    $season = match (true) {
        $month <= 3  => 'WINTER',
        $month <= 6  => 'SPRING',
        $month <= 9  => 'SUMMER',
        default      => 'FALL',
    };
    return [$season, $year];
}

function anime_url(int|string $id): string
{
    return url('anime/' . (int) $id);
}

function watch_url(int|string $id, int|string $episode = 1, string $type = 'dub'): string
{
    // Always explicit: `/watch/{id}/{ep}?type=dub|sub` so the URL state is
    // shareable and never depends on the route default.
    $url = url('watch/' . (int) $id . '/' . max(0, (int) $episode));
    return $url . '?type=' . ($type === 'sub' ? 'sub' : 'dub');
}

function genre_url(string $genre): string
{
    return url('genre/' . rawurlencode($genre));
}

function search_url(string $q = ''): string
{
    return url('search') . ($q !== '' ? '?q=' . rawurlencode($q) : '');
}

function browse_link(string $type): string
{
    return url('browse/' . $type);
}

function season_text(array $anime): string
{
    $season = season_label($anime['season'] ?? null);
    $year   = year_of($anime);
    return $season !== '—' && $year !== '—' ? $season . ' ' . $year : ($year !== '—' ? $year : '');
}

function genres_text(array $anime, int $limit = 3): string
{
    $genres = array_slice(array_filter((array) ($anime['genres'] ?? [])), 0, $limit);
    return implode(' · ', $genres);
}

function meta_line(array $anime): string
{
    $bits = [];
    if (($anime['year'] ?? null)) {
        $bits[] = (string) $anime['year'];
    }
    if (($anime['format'] ?? null)) {
        $bits[] = format_label($anime['format']);
    }
    $eps = $anime['episodes'] ?? null;
    if (is_numeric($eps) && (int) $eps > 0) {
        $bits[] = (int) $eps . ' eps';
    } elseif (($anime['status'] ?? '') === 'NOT_YET_RELEASED') {
        $bits[] = 'Upcoming';
    }
    return implode(' · ', $bits);
}

function nav_active(string $prefix): string
{
    $path = current_path();
    return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/') ? ' is-active' : '';
}

function cache_meta_headers(array $meta): void
{
    if (!empty($meta['cache'])) {
        header('X-Emnex-Cache: ' . $meta['cache']);
    }
    if (isset($meta['age'])) {
        header('X-Emnex-Cache-Age: ' . (string) $meta['age']);
    }
}
