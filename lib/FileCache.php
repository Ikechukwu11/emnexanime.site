<?php
declare(strict_types=1);

/**
 * Atomic file cache.
 *
 * Used for short-lived, high-churn payloads: resolved stream sources (signed
 * URLs expire quickly) and request locks. Also fully supports stale-if-error.
 */
final class FileCache
{
    public function __construct(private readonly string $dir)
    {
        foreach ([$this->dir, $this->dir . '/locks'] as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    private function pathFor(string $key): string
    {
        $hash = sha1($key);
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }

    /**
     * @return array{data:mixed, fresh:bool, stale_ok:bool, created:int, expires:int, age:int}|null
     */
    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !array_key_exists('data', $envelope)) {
            return null;
        }
        $now = time();
        return [
            'data'      => $envelope['data'],
            'created'   => (int) ($envelope['created'] ?? 0),
            'expires'   => (int) ($envelope['expires'] ?? 0),
            'fresh'     => (int) ($envelope['expires'] ?? 0) >= $now,
            'stale_ok'  => (int) ($envelope['stale_until'] ?? 0) >= $now,
            'age'       => max(0, $now - (int) ($envelope['created'] ?? $now)),
        ];
    }

    public function put(string $key, mixed $data, int $ttl, int $staleTtl): void
    {
        $path = $this->pathFor($key);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $now = time();
        $payload = json_encode([
            'key'         => $key,
            'created'     => $now,
            'expires'     => $now + max(1, $ttl),
            'stale_until' => $now + max($ttl, $staleTtl),
            'data'        => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            return;
        }

        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }

        if (random_int(1, 200) === 1) {
            $this->prune();
        }
    }

    public function forget(string $key): void
    {
        @unlink($this->pathFor($key));
    }

    /**
     * Acquire a non-blocking lock, waiting briefly if another process is
     * already fetching the same resource.
     *
     * @return resource|null
     */
    public function lock(string $key, int $waitMs = 2500)
    {
        $path = $this->dir . '/locks/' . sha1($key) . '.lock';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }

        $deadline = microtime(true) + ($waitMs / 1000);
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        fclose($handle);
        return null;
    }

    /**
     * @param resource|null $handle
     */
    public function unlock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @return array{data:mixed, cache:string, age:int}
     */
    public function remember(string $key, int $ttl, int $staleTtl, callable $producer): array
    {
        $cached = $this->get($key);
        if ($cached && $cached['fresh']) {
            return ['data' => $cached['data'], 'cache' => 'HIT', 'age' => $cached['age']];
        }

        $lock = $this->lock($key);
        if ($lock === null) {
            // Someone else may have finished while we waited for the lock.
            $cached = $this->get($key);
            if ($cached && $cached['fresh']) {
                return ['data' => $cached['data'], 'cache' => 'HIT', 'age' => $cached['age']];
            }
        }

        try {
            $cached = $this->get($key);
            if ($cached && $cached['fresh']) {
                return ['data' => $cached['data'], 'cache' => 'HIT', 'age' => $cached['age']];
            }

            $data = $producer();
            $this->put($key, $data, $ttl, $staleTtl);
            return ['data' => $data, 'cache' => 'MISS', 'age' => 0];
        } catch (Throwable $e) {
            $cached = $this->get($key);
            if ($cached && $cached['stale_ok']) {
                return ['data' => $cached['data'], 'cache' => 'STALE', 'age' => $cached['age']];
            }
            throw $e;
        } finally {
            $this->unlock($lock);
        }
    }

    public function prune(): int
    {
        $cutoff = time() - 172800; // 2 days
        $count  = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !str_ends_with($file->getFilename(), '.lock') && $file->getMTime() < $cutoff) {
                if (@unlink($file->getPathname())) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function clear(): void
    {
        $this->pruneOlderThan(0);
    }

    private function pruneOlderThan(int $age): void
    {
        $cutoff = time() - $age;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !str_ends_with($file->getFilename(), '.lock') && $file->getMTime() <= $cutoff) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * @return array{files:int, bytes:int}
     */
    public function stats(): array
    {
        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) {
                $files++;
                $bytes += $file->getSize();
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }
}
