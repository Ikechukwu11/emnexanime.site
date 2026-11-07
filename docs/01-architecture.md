# 01 · Architecture

This document describes the system the way it actually runs today, structured so a rewrite can re-implement each layer in isolation. Everything here is framework-neutral: it describes *responsibilities and contracts*, not OOP specifics.

## 1. Layer model

```
 Browser
   │  HTML (server-rendered by PHP) + vanilla JS assets
   ▼
 ┌───────────────────────────────────────────────────────────┐
 │ Public layer   index.php   — front controller             │
 │                .htaccess / router.php — pretty URLs+deny  │
 ├───────────────────────────────────────────────────────────┤
 │ Internal API   api.php     — JSON endpoints for the UI    │
 ├───────────────────────────────────────────────────────────┤
 │ Service        EmnexApi    — one place that knows the     │
 │                             upstream: paths, fallbacks,   │
 │                             caching policy per endpoint   │
 ├──────────────────────────────────────┬────────────────────┤
 │ Cache L1: SQLite (metadata, long TTL)│ Cache L2: files    │
 │ storage/cache.sqlite                 │ storage/cache/     │
 │ via SqliteCache                      │ via FileCache      │
 ├──────────────────────────────────────┴────────────────────┤
 │ HTTP client   HttpClient (cURL wrapper, adds x-api-key)   │
 └───────────────────────────────────────────────────────────┘
   │
   ▼  https://api.emnexanime.site (key side-server only)
 Upstream EmnexAnime API  (rps 2 · 1000 req/month free plan)
```

Core rule: **the browser only ever talks to `index.php` (HTML) and `api.php` (JSON).** The upstream key never leaves the server.

## 2. Request lifecycle (HTML routes)

`index.php` is a single procedural script:

1. `require lib/bootstrap.php` — config load, error/exception policy, storage dirs, helpers (`e()`, `url()`, `view` rendering, data formatters), autoloader for `lib/`.
2. Send security headers (nosniff, referrer policy, frame options).
3. Compute `$path` from the request URI (`current_path()` strips subdirectory + legacy `/index.php`).
4. If `?p=` legacy query is present, translate it into a pretty path first (works even without mod_rewrite).
5. Route match → call `EmnexApi` → set `Cache-Control` + `X-Emnex-Cache*` headers → `page($view, $vars, $options)` renders a view file inside the layout and exits.
6. Any `ApiException` becomes a friendly error page with mapped HTTP status (404 stays 404; 401/403 upstream → 502; 429 with a "cache has you covered" message). Uncaught `Throwable` → 500 + log.

Views never call the API themselves; the route fetches, views only format (`View::card()`, `View::row()`, `View::icon()` etc. for reusable HTML fragments).

## 3. Request lifecycle (JSON routes)

`api.php` mirrors the same bootstrap, then dispatches `?action=…`:

- Normalises/validates query params (`query_int()` clamps ranges).
- Delegates to `EmnexApi` (which consults the caches).
- Wraps every response in `{ok: true, data, meta}` / `{ok: false, error, meta}`; `meta` carries `cache` (HIT/MISS/STALE/BYPASS) and `age` in seconds.
- Never lets browser cache signed stream URLs (`Cache-Control: no-store` for `sources`).

## 4. Module responsibilities (port-friendly inventory)

### lib/bootstrap.php — "kernel"
| Concern | What it must provide in any port |
| --- | --- |
| Config | Load `config.php`; create `storage/`, `storage/cache/`, `storage/logs/` |
| Base URL | `BASE_PATH` from `SCRIPT_NAME` dirname; `url()`, `asset()` (mtime-versioned), `absolute_url()` |
| Output | `e()` HTML escape, `json_out/json_ok/json_error` envelopes, `redirect()` |
| View | `render_view($name, $vars)` (extract + include + output buffer), `render_layout($content, $options)` |
| Text utils | `clean_description`, `excerpt_text`, `description_html` |
| Anime formatters | `title_of`, `alt_title_of`, `poster_of`, `banner_of`, `accent_of`, `rating_of`, `year_of`, `format_label`, `status_label`, `season_label`, `season_text`, `genres_text`, `meta_line`, `episodes_label`, `human_count`, `duration_label`, `initials_of` |
| URL builders | `anime_url`, `watch_url`, `genre_url`, `search_url`, `browse_link` |
| Request utils | `query()` (GET, trimmed), `query_int()` (regex-validated + clamped), `current_path()`, `is_ajax()` |
| Misc | timezone, mb encoding, `log_error()` → `storage/logs/app.log`, `cache_meta_headers()` |

### lib/EmnexApi.php — "service"
The single class that knows upstream paths and caching policy:
- `home()`, `genres()`, `schedule()`, `anime($id)`, `episodes($id)`, `characters($id)`, `seasons($id)` → `cachedGet(path, query, ttlKey)` (SQLite cache).
- `feed($type, $page, $perPage, $extra)` → maps `trending|popular|recent|seasonal|genre|search` onto upstream endpoints.
- `sources($id, $episode, $type, $kind, $malId, $refresh)` → resolves a playable stream; when `kind=anilist` it tries the AniList id first, then the MAL id; per-attempt errors are collected and surfaced in the final message.
- `cacheStats()` → combined stats of both cache layers for diagnostics.

### lib/HttpClient.php — "transport"
cURL GET returning `{status, body, headers}`; injects `x-api-key` when a key is present; custom `User-Agent`; 8s connect / 25s total timeout; header function collects response headers (e.g. for the mark/segue of redirects we don't follow).

### lib/SqliteCache.php — metadata cache
A single file `storage/cache.sqlite`, table `cache(url, status, body, fetched_at, expires_at, stale_until)`. Provides `get($url)` → `{status, body, fetched_at, expires_at, stale_until}` (row shapes the service reasons over), `put(...)`, `forget(...)` — and `stats()` for diagnostics. WAL mode on. (See `docs/03-caching.md` for semantics.)

### lib/FileCache.php — stream/request cache
Directory of JSON entry files under `storage/cache/` hashed by key, each entry `{key, data, cachedAt, expiresAt, staleUntil}`. Adds **lock files** (`lock() → token`, `unlock($token)`) used to coalesce concurrent upstream fetches, atomic writes via temp-file + rename. Same `stats()` shape.

### lib/View.php — HTML fragments
`icon($name, $size)` (inline SVG library), `card($anime, $opts)` (the canonical markup all cards must reproduce — server and JS renderers must stay DOM-identical), `posterCard()`, `row($title, $items, $href, $opts)` (horizontal scroller with arrow buttons), `genreTile()`, `emptyState()`.

### views/ — output templates
`layout.php` (head/SEO/social, header+search, footer, mobile nav, toasts, modal, `window.EMNX` config, per-page script loading), plus `home`, `browse`, `search`, `anime`, `watch`, `genres`, `schedule`, `watchlist`, `error`.

## 5. Design invariants (keep these in any rewrite)

1. **Key isolation.** `keys.txt`/`config.php`/`storage/`/`lib/`/`views/`/`probe/` must never be web-readable (deny in server config, guard in `config.php` via `EMNX` constant).
2. **Same-origin proxy.** Browser fetches only `api.php`; never upstream directly.
3. **Envelope.** All internal JSON: `{ok:true,data,meta}` / `{ok:false,error,meta}` with safe-to-display error text.
4. **Cache headers per route.** HTML routes emit `public,max-age…` or `no-store`; `sources` is always `no-store`.
5. **Card DOM parity.** PHP `View::card()` ≡ JS `EMNX.cardHTML()` (class list, structure, hooks) — `docs/04-frontend.md` documents the exact markup.
6. **Exact-episode URLs.** `/watch/{animeId}/{ep}` (+ `?type=dub`); legacy `/anime/{id}/watch/{ep}` 302-redirects to it.
7. **Client state stays client-side.** No server-side profiles; watchlist/progress in localStorage only.
8. **Rate-limit kindness.** Every upstream call must go through cache-first logic; per_page ≤ 50; the JS shows at most 5 infinite-scroll auto pages before requiring an explicit click.
