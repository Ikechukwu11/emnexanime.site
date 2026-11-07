<?php
declare(strict_types=1);

/**
 * Server-side client for the EmnexAnime API.
 *
 * Metadata goes through the SQLite cache (long TTL), while stream resolution
 * goes through the file cache (short TTL because the URLs are signed).
 */
final class EmnexApi
{
    private HttpClient $http;
    private SqliteCache $cache;
    private FileCache $fileCache;
    private string $base;
    private array $ttl;
    private string $apiKey;

    public function __construct(array $config)
    {
        $this->base      = rtrim((string) $config['api_base'], '/');
        $this->apiKey    = trim((string) ($config['api_key'] ?? ''));
        $this->ttl       = (array) $config['ttl'];
        $this->http      = new HttpClient($this->apiKey, 25);
        $this->cache     = new SqliteCache((string) $config['sqlite_cache']);
        $this->fileCache = new FileCache((string) $config['file_cache']);
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function cacheStats(): array
    {
        return [
            'sqlite' => $this->cache->stats(),
            'files'  => $this->fileCache->stats(),
        ];
    }

    // ------------------------------------------------------------- metadata
    public function home(): array
    {
        return $this->cachedGet('/api/home', [], 'home');
    }

    public function genres(): array
    {
        return $this->cachedGet('/api/genres', [], 'genres');
    }

    public function schedule(): array
    {
        return $this->cachedGet('/api/schedule', [], 'schedule');
    }

    public function anime(int $id): array
    {
        return $this->cachedGet('/api/anime/' . $id, [], 'detail');
    }

    public function seasons(int $id): array
    {
        return $this->cachedGet('/api/anime/' . $id . '/seasons', [], 'seasons');
    }

    public function episodes(int $id): array
    {
        return $this->cachedGet('/api/anime/' . $id . '/episodes', [], 'episodes');
    }

    public function characters(int $id): array
    {
        return $this->cachedGet('/api/anime/' . $id . '/characters', [], 'characters');
    }

    /**
     * Catalog feed. Supported: trending, popular, recent, seasonal, genre, search.
     */
    public function feed(string $type, int $page = 1, int $perPage = 24, array $extra = []): array
    {
        $page    = max(1, min(1000, $page));
        $perPage = max(1, min(50, $perPage));
        $query   = ['page' => $page, 'perPage' => $perPage];

        switch ($type) {
            case 'trending':
            case 'popular':
            case 'recent':
                return $this->cachedGet('/api/' . $type, $query, 'list');

            case 'seasonal':
                $season = strtoupper(trim((string) ($extra['season'] ?? '')));
                $year   = (int) ($extra['year'] ?? 0);
                if (in_array($season, ['WINTER', 'SPRING', 'SUMMER', 'FALL'], true)) {
                    $query['season'] = $season;
                }
                if ($year >= 1940 && $year <= 2100) {
                    $query['year'] = $year;
                }
                return $this->cachedGet('/api/seasonal', $query, 'list');

            case 'genre':
                $genre = trim((string) ($extra['genre'] ?? ''));
                if ($genre === '') {
                    throw new ApiException(400, 'A genre is required.');
                }
                return $this->cachedGet('/api/genre/' . rawurlencode($genre), $query, 'list');

            case 'search':
                $q = trim((string) ($extra['q'] ?? ''));
                if ($q === '') {
                    throw new ApiException(400, 'Enter a search term.');
                }
                return $this->cachedGet('/api/search/' . rawurlencode($q), $query, 'search');

            default:
                throw new ApiException(400, 'Unknown catalog feed.');
        }
    }

    // -------------------------------------------------------------- streams
    /**
     * Resolve a playable source. Tries AniList first, then MAL as a fallback
     * when the title has an idMal.
     */
    public function sources(
        int $id,
        int $episode,
        string $type = 'sub',
        string $kind = 'anilist',
        ?int $malId = null,
        bool $refresh = false
    ): array {
        $type = $type === 'dub' ? 'dub' : 'sub';
        $kind = $kind === 'mal' ? 'mal' : 'anilist';

        $attempts = [];
        $modes    = [];

        if ($kind === 'mal') {
            $modes[] = ['mal', $id];
        } else {
            $modes[] = ['anilist', $id];
            if ($malId !== null && $malId > 0 && $malId !== $id) {
                $modes[] = ['mal', $malId];
            }
        }

        $lastError = null;
        foreach ($modes as [$mode, $modeId]) {
            try {
                $result = $this->resolveSource($mode, $modeId, $episode, $type, $refresh);
                $result['attempts'] = $attempts;
                return $result;
            } catch (ApiException $e) {
                $attempts[] = $mode . ': ' . $e->getMessage();
                $lastError  = $e;
            }
        }

        $status  = $lastError?->httpStatus ?? 502;
        $message = 'No playable stream was found for episode ' . $episode . ' (' . $type . ').';
        if ($attempts) {
            $message .= ' ' . implode(' ', $attempts);
        }
        throw new ApiException($status === 404 ? 404 : 502, $message);
    }

    private function resolveSource(string $mode, int $id, int $episode, string $type, bool $refresh): array
    {
        if (!$this->hasKey()) {
            throw new ApiException(500, 'No API key is configured. Add it to keys.txt.');
        }
        if ($id <= 0) {
            throw new ApiException(400, 'Invalid anime id.');
        }
        if ($episode < 0 || $episode > 99999) {
            throw new ApiException(400, 'Invalid episode number.');
        }

        $path = $mode === 'mal'
            ? '/api/sources/mal/' . $id . '/' . $episode
            : '/api/sources/' . $id . '/' . $episode;
        $url  = $this->buildUrl($path, ['type' => $type]);
        $key  = 'sources:' . $url;

        if ($refresh) {
            $this->fileCache->forget($key);
        }

        $cached = $this->fileCache->get($key);
        if ($cached && $cached['fresh']) {
            $data = (array) $cached['data'];
            if (!empty($data['error'])) {
                throw new ApiException($this->mapStatus((int) ($data['_status'] ?? 502)), (string) $data['error']);
            }
            return $this->result($data, 'HIT', (int) $cached['age']);
        }

        $lock = $this->fileCache->lock($key);
        if ($lock === null) {
            $cached = $this->fileCache->get($key);
            if ($cached && $cached['fresh'] && empty($cached['data']['error'])) {
                return $this->result((array) $cached['data'], 'HIT', (int) $cached['age']);
            }
        }

        try {
            // Double-check after acquiring the lock.
            $cached = $this->fileCache->get($key);
            if ($cached && $cached['fresh']) {
                $data = (array) $cached['data'];
                if (!empty($data['error'])) {
                    throw new ApiException($this->mapStatus((int) ($data['_status'] ?? 502)), (string) $data['error']);
                }
                return $this->result($data, 'HIT', (int) $cached['age']);
            }

            $response = $this->http->get($url);
            $decoded  = $this->decode($response['body'], $url);
            $success  = $response['status'] >= 200
                && $response['status'] < 300
                && is_array($decoded)
                && empty($decoded['error'])
                && !empty($decoded['hls']);

            if ($success) {
                $ttl = (int) ($this->ttl['sources'] ?? 180);
                $this->fileCache->put($key, $decoded, $ttl, 900);
                return $this->result($decoded, 'MISS', 0);
            }

            $message = $this->upstreamMessage($decoded, $response['status']);

            if ($cached && $cached['stale_ok']) {
                log_error('Sources upstream failed (' . $response['status'] . '); serving stale file cache for ' . $url);
                return $this->result((array) $cached['data'], 'STALE', (int) $cached['age']);
            }

            // Small negative cache to protect the API quota on dead episodes.
            $this->fileCache->put($key, [
                'error'    => $message,
                '_status'  => $response['status'],
                '_cachedAt' => time(),
            ], 45, 90);

            throw new ApiException($this->mapStatus($response['status']), $message);
        } catch (ApiException $e) {
            $cached = $this->fileCache->get($key);
            if ($cached && $cached['stale_ok'] && empty($cached['data']['error'])) {
                return $this->result((array) $cached['data'], 'STALE', (int) $cached['age']);
            }
            throw $e;
        } catch (Throwable $e) {
            $cached = $this->fileCache->get($key);
            if ($cached && $cached['stale_ok'] && empty($cached['data']['error'])) {
                log_error('Sources network failure; serving stale file cache: ' . $e->getMessage());
                return $this->result((array) $cached['data'], 'STALE', (int) $cached['age']);
            }
            log_error('Sources network failure: ' . $e->getMessage());
            throw new ApiException(502, 'The stream service is unreachable right now.');
        } finally {
            $this->fileCache->unlock($lock);
        }
    }

    // -------------------------------------------------------------- internals
    private function cachedGet(string $path, array $query, string $ttlKey): array
    {
        if (!$this->hasKey()) {
            throw new ApiException(500, 'No API key is configured. Add your EmnexAnime key to keys.txt.');
        }

        $url = $this->buildUrl($path, $query);
        $ttl = (int) ($this->ttl[$ttlKey] ?? 900);
        // Keep stale data longer for expensive/stable endpoints.
        $staleTtl = match ($ttlKey) {
            'detail', 'episodes', 'characters', 'seasons', 'genres' => 604800,
            'home' => 21600,
            default => 86400,
        };

        $row = $this->cache->get($url);
        if ($row && $row['expires_at'] >= time()) {
            $data = $this->decode($row['body'], $url);
            if ($data !== null) {
                return $this->result($data, 'HIT', time() - $row['fetched_at']);
            }
            $this->cache->forget($url);
            $row = null;
        }

        $lock = $this->fileCache->lock('meta:' . $url);
        if ($lock === null) {
            // Another request is fetching. Give it a moment, then re-check.
            for ($i = 0; $i < 6; $i++) {
                usleep(150_000);
                $row = $this->cache->get($url);
                if ($row && $row['expires_at'] >= time()) {
                    $data = $this->decode($row['body'], $url);
                    if ($data !== null) {
                        return $this->result($data, 'HIT', time() - $row['fetched_at']);
                    }
                }
            }
        }

        try {
            // Double-check after acquiring the lock.
            $row = $this->cache->get($url);
            if ($row && $row['expires_at'] >= time()) {
                $data = $this->decode($row['body'], $url);
                if ($data !== null) {
                    return $this->result($data, 'HIT', time() - $row['fetched_at']);
                }
            }

            $response = $this->http->get($url);
            $decoded  = $this->decode($response['body'], $url);
            $valid    = $response['status'] >= 200
                && $response['status'] < 300
                && is_array($decoded)
                && empty($decoded['error']);

            if ($valid) {
                $this->cache->put($url, $response['body'], $response['status'], $ttl, $staleTtl);
                return $this->result($decoded, 'MISS', 0);
            }

            $message = $this->upstreamMessage($decoded, $response['status']);
            if ($row && $row['stale_until'] >= time()) {
                $staleData = $this->decode($row['body'], $url);
                if ($staleData !== null) {
                    log_error('Upstream error ' . $response['status'] . '; serving stale SQLite cache for ' . $url);
                    return $this->result($staleData, 'STALE', time() - $row['fetched_at']);
                }
            }

            throw new ApiException($this->mapStatus($response['status']), $message);
        } catch (ApiException $e) {
            $row = $this->cache->get($url);
            if ($row && $row['stale_until'] >= time()) {
                $staleData = $this->decode($row['body'], $url);
                if ($staleData !== null) {
                    return $this->result($staleData, 'STALE', time() - $row['fetched_at']);
                }
            }
            throw $e;
        } catch (Throwable $e) {
            $row = $this->cache->get($url);
            if ($row && $row['stale_until'] >= time()) {
                $staleData = $this->decode($row['body'], $url);
                if ($staleData !== null) {
                    log_error('Upstream failure; serving stale cache: ' . $e->getMessage());
                    return $this->result($staleData, 'STALE', time() - $row['fetched_at']);
                }
            }
            log_error('Upstream failure: ' . $e->getMessage());
            throw new ApiException(502, 'The anime service is unreachable right now. Please try again.');
        } finally {
            $this->fileCache->unlock($lock);
        }
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->base . $path;
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    private function decode(string $body, string $url = ''): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (JsonException $e) {
            log_error('Invalid JSON from upstream: ' . $e->getMessage() . ($url !== '' ? ' (' . $url . ')' : ''));
            return null;
        }
    }

    private function upstreamMessage(?array $decoded, int $status): string
    {
        $message = trim((string) ($decoded['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }
        return match ($status) {
            401, 403 => 'The upstream API rejected the configured key.',
            404      => 'This title or episode could not be found upstream.',
            429      => 'The API rate limit or monthly quota was reached.',
            default  => 'The upstream API returned an error (HTTP ' . $status . ').',
        };
    }

    private function mapStatus(int $upstream): int
    {
        return match ($upstream) {
            400      => 400,
            404      => 404,
            429      => 429,
            401, 403 => 502,
            default  => 502,
        };
    }

    /**
     * @return array{data:array, cache:string, age:int}
     */
    private function result(array $data, string $cache, int $age): array
    {
        return ['data' => $data, 'cache' => $cache, 'age' => max(0, $age)];
    }
}
