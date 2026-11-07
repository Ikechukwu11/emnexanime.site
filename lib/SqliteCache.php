<?php
declare(strict_types=1);

/**
 * Persistent metadata cache (separate SQLite database).
 * Stores raw JSON API responses with TTL + a stale-if-error window.
 */
final class SqliteCache
{
    private PDO $pdo;

    public function __construct(string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS http_cache (
                cache_key   TEXT PRIMARY KEY,
                url         TEXT NOT NULL,
                body        BLOB NOT NULL,
                status      INTEGER NOT NULL DEFAULT 200,
                fetched_at  INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL,
                stale_until INTEGER NOT NULL,
                bytes       INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_http_cache_expires ON http_cache(expires_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_http_cache_stale ON http_cache(stale_until)');
    }

    /**
     * @return array{body:string,status:int,fetched_at:int,expires_at:int,stale_until:int}|null
     */
    public function get(string $url): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT body, status, fetched_at, expires_at, stale_until FROM http_cache WHERE cache_key = :k LIMIT 1'
        );
        $stmt->execute([':k' => sha1($url)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['body'] = is_resource($row['body']) ? (string) stream_get_contents($row['body']) : (string) $row['body'];
        return $row;
    }

    public function put(string $url, string $body, int $status, int $ttl, int $staleTtl): void
    {
        $now       = time();
        $expires   = $now + max(1, $ttl);
        $staleTill = $now + max($ttl, $staleTtl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO http_cache (cache_key, url, body, status, fetched_at, expires_at, stale_until, bytes)
             VALUES (:k, :u, :b, :s, :f, :e, :st, :n)
             ON CONFLICT(cache_key) DO UPDATE SET
                url = excluded.url,
                body = excluded.body,
                status = excluded.status,
                fetched_at = excluded.fetched_at,
                expires_at = excluded.expires_at,
                stale_until = excluded.stale_until,
                bytes = excluded.bytes'
        );
        $stmt->execute([
            ':k'  => sha1($url),
            ':u'  => $url,
            ':b'  => $body,
            ':s'  => $status,
            ':f'  => $now,
            ':e'  => $expires,
            ':st' => $staleTill,
            ':n'  => strlen($body),
        ]);

        // Light probabilistic housekeeping.
        if (random_int(1, 100) === 1) {
            $this->prune();
        }
    }

    public function forget(string $url): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM http_cache WHERE cache_key = :k');
        $stmt->execute([':k' => sha1($url)]);
    }

    public function prune(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM http_cache WHERE stale_until < :cutoff');
        $stmt->execute([':cutoff' => time() - 86400]);
        return $stmt->rowCount();
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM http_cache');
        $this->pdo->exec('VACUUM');
    }

    /**
     * @return array{entries:int, fresh:int, stale:int, bytes:int, newest:int, oldest:int}
     */
    public function stats(): array
    {
        $now = time();
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS entries,
                    SUM(CASE WHEN expires_at >= ' . $now . ' THEN 1 ELSE 0 END) AS fresh,
                    SUM(CASE WHEN expires_at < ' . $now . ' AND stale_until >= ' . $now . ' THEN 1 ELSE 0 END) AS stale,
                    COALESCE(SUM(bytes), 0) AS bytes,
                    COALESCE(MAX(fetched_at), 0) AS newest,
                    COALESCE(MIN(fetched_at), 0) AS oldest
             FROM http_cache'
        )->fetch();

        return [
            'entries' => (int) ($row['entries'] ?? 0),
            'fresh'   => (int) ($row['fresh'] ?? 0),
            'stale'   => (int) ($row['stale'] ?? 0),
            'bytes'   => (int) ($row['bytes'] ?? 0),
            'newest'  => (int) ($row['newest'] ?? 0),
            'oldest'  => (int) ($row['oldest'] ?? 0),
        ];
    }
}
