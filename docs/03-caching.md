# 03 · Caching design

Two independent cache layers with different roles. Both must survive being disabled (e.g. read-only storage degrades gracefully) and both support **stale-if-error** so upstream outages or quota exhaustion never blank the site.

```
                    ┌… SQLite (metadata, minutes–hours) …┐
 request ──► fresh? ─ HIT                          ──► browser
             │                                     (SWR headers)
             ▼ lock (file cache)
        spin-wait for the winner ×6 (150 ms steps)
             ▼
        curl upstream ── success ──► store ──► MISS
                        failure ──► stale row? ──► STALE ──► browser
                                    else      throw mapped status
```

## 1. Layer 1 — SQLite cache (metadata)

- **File:** `storage/cache.sqlite`, WAL journaling.
- **Schema (one row per upstream URL):**

| column | purpose |
| --- | --- |
| `url` | the fully-built upstream URL including query string (the cache key) |
| `status` | last upstream HTTP status (kept for diagnostics) |
| `body` | raw upstream JSON body, stored lossless |
| `fetched_at` | unix seconds — source of `age` |
| `expires_at` | fresh until this instant |
| `stale_until` | stale-if-error window end |

- **Read flow:** fresh row (`expires_at >= now`) → `HIT`; during a lock-window, waiting requests poll every 150 ms up to 6× so fan-out collapses into one upstream call.
- **Store flow:** upstream `2xx` + valid JSON + no `error` field ⇒ `put(url, body, status, ttl, staleTtl)`.
- **Stale-if-error:** on any upstream failure (HTTP error, invalid JSON, network) an existing row inside `stale_until` is served as `STALE` (header `X-Emnex-Cache: STALE`) and an error is logged.
- **TTL table** (`config.php` `ttl` map — authoritative values):

| key | endpoints | fresh | stale |
| --- | --- | --- | --- |
| `home` | `/api/home` | 30 min | 6 h |
| `list` | trending/popular/recent/seasonal/genre | 15 min | 24 h |
| `search` | `/api/search/*` | 15 min | 24 h |
| `schedule` | `/api/schedule` | 15 min | 24 h |
| `detail` | `/api/anime/{id}` | 3 h | **7 days** |
| `episodes` | `/api/anime/{id}/episodes` | 6 h | 7 days |
| `seasons` | `/api/anime/{id}/seasons` | 12 h | 7 days |
| `characters` | `/api/anime/{id}/characters` | 24 h | 7 days |
| `genres` | `/api/genres` | 24 h | 7 days |
| `sources` *(file cache)* | `/api/sources/…` | 3 min | 15 min |

Rationale in one line: metadata pages are the *traffic* (every user page view hits home/browse/detail), so they must almost never reach upstream; stream URLs are signed & short-lived, so they must expire quickly.

## 2. Layer 2 — File cache (stream sources, locks)

- **Directory:** `storage/cache/`; one JSON entry per key (hashed filename), entry shape `{key, data, cachedAt, expiresAt, staleUntil}`.
- **Semantics beyond plain KV:**
  - `lock($key)` → unique token or `null` if someone else holds it; `unlock($key, token)`. Used for *request coalescing*: concurrent requests for the same cold URL — one fetches, the losers spin/poll then read the fresh result (SQLite cache) or re-check the file cache.
  - Atomic writes: write to temp file + rename (never a torn read).
  - Stale windows mirror the SQLite layer (see table above; sources 15 min).
- **Source resolution flow** (`EmnexApi::resolveSource`, the only current consumer):
  1. `refresh` ⇒ `forget($key)` first.
  2. Fresh hit → return (invalid cached payloads, i.e. `{"error":…}` negatives, throw their recorded status instead of succeeding).
  3. Lock; not obtained → re-check fresh, else fall through.
  4. Upstream call → success (2xx + `hls` non-empty) ⇒ store `{data, ttl 180 s, stale 900 s}`.
  5. Failure ⇒ serve stale if available; otherwise **negative cache** `{error, _status, _cachedAt}` stored for **45 s fresh / 90 s stale** so a dead episode link can't burn quota on every click.
- **No browser caching:** `api.php?action=sources` always answers `Cache-Control: no-store` (URLs are signed upstream and expire within minutes).

## 3. Why two layers (port consideration)

| Concern | SQLite cache | File cache |
| --- | --- | --- |
| Lifetimes | minutes–days | seconds–minutes |
| Payloads | whole upstream JSON documents (up to ~100 KB) | small source payloads + lock/negative files |
| Failure mode | whole-DB available or not | per-entry; system keeps working if a file is corrupt |
| Extra features | — | locks (coalescing), negative TTL |

In a procedural port these two layers can stay exactly two function families:

```
# metadata
meta_get(url)  -> ?{status, body, fetched_at, expires_at, stale_until}
meta_put(url, body, status, ttl, stale_ttl): void
meta_forget(url): void

# streams / ephemeral
fc_get(key)    -> ?{fresh: bool, stale_ok: bool, data: array, age: int}
fc_put(key, data, ttl, stale_ttl): void
fc_forget(key): void
fc_lock(key)   -> ?token ;  fc_unlock(token): void
fc_stats(): array
```

…with a tiny `api()` service that composes them, as documented in `docs/02-routes-and-api.md` §3.

## 4. Diagnostics & housekeeping

- `api.php?action=stats` (also `/api/stats`) returns:
  `sqlite {entries, fresh, stale, bytes, newest, oldest}` and `files {files, bytes}` — useful smoke-check after deployment.
- Both caches self-clean: SQLite rows are pruned/heeded by expiry at read time; stale file entries are simply overwritten by the next successful resolution, and manifest/stale garbage past its stale window is ignored (kept on disk only as dead weight — safe to delete the whole `storage/cache/` directory at any time; SQLite stores everything it needs inline).
- `X-Emnex-Cache` / `X-Emnex-Cache-Age` headers let you watch HIT/MISS/STALE per page load in devtools.
- All failures are logged to `storage/logs/app.log` (upstream errors, invalid JSON, network failure + which layer served stale).

## 5. Quota math (free plan: 1000 requests/month, 2 rps)

- 1000 requests ÷ 30 days ≈ **33 upstream requests/day**. Warm caches make a steady-state site cost *zero* until TTL expiry; the budget is then dominated by:
  - home: 48/day (max) → amortized far less because every TTL hit saves a call
  - lists/search: only on cold keys (each key = per-type+page-1..n)
  - detail/episodes: covered for 3–6 h and 7-day stale windows
- Anti run-away guards: `perPage ≤ 50` clamp (`max_per_page`), `page ≤ 1000`, the sources negative cache (45 s), and FE-side inactivity cap (max 5 auto LOADED pages before requiring a click).

When capacity is needed, the migration path is: raise TTLs for `list`/`home`, or upgrade the upstream plan — no code changes required.
