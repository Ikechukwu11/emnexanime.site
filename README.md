# EmnexAnime

A self-hosted anime discovery & streaming front-end. Vanilla **PHP 8** (flat, no framework) + **SQLite** + vanilla **JS/CSS**. It proxies the [EmnexAnime API](https://api.emnexanime.site/) behind a same-origin internal API so the upstream key never reaches the browser, wraps it in two cache layers (SQLite for metadata, files for streams), and ships a dependency-free HLS player.

> No media is hosted here. Metadata comes from **AniList**, stream URLs are resolved from third-party sources through the EmnexAnime API. Personal / educational use.

---

## Features

| Area | What it does |
| --- | --- |
| Discovery | Spotlight hero carousel, trending/seasonal/popular/top-rated/upcoming rows, genre pages, 7-day airing schedule in local time |
| Detail | Full AniList metadata, lazy-loaded episode list with batches + jump-to, relations, characters, trailer modal, next-episode countdown |
| Player | hls.js (vendored, no CDN at runtime), custom controls, quality/audio/subtitle/speed pickers, skip intro/outro windows, resume where you left off, autoplay-next |
| Tracks | Subtitles proxied same-origin (`api.php?action=subtitle`) so CORS never kills captions |
| Personal state | Watchlist + watch progress in `localStorage` only — no accounts, no server profiles |
| Performance | Dual-layer caching with TTL + stale-if-error, request coalescing locks, negative cache for dead episodes, incremental `Load more` capping to protect the free API quota |
| Hardening | API key read server-side from `keys.txt` (denied to the web), `.htaccess`/`router.php` block `config.php`, `storage/`, `lib/`, `views/`, `probe/`, dotfiles |

## Quick start

```
php -S 127.0.0.1:8080 router.php
```

Requires: PHP 8 with `curl`, `pdo_sqlite`, `mbstring`, `json`.

1. Put your EmnexAnime key in `keys.txt` (one line, starts with `emnx_…`). Or export `EMNEX_API_KEY`.
2. Serve the folder. `router.php` is the built-in server router; Apache uses `.htaccess`; anything else needs an equivalent front-controller config (see `docs/02-routes-and-api.md`).
3. Open `http://127.0.0.1:8080/`. Diagnostics: `/api.php?action=stats` shows SQLite/file cache fill state.

Optional environment flags: `EMNEX_DEBUG=1` (verbose errors), `EMNEX_API_BASE` (override upstream URL).

## Project layout

```
index.php            Front controller: all public routes, error pages, SEO wiring
api.php              Internal JSON API for the browser (same-origin proxy)
config.php           Site config + TTL table (denied to the web)
keys.txt             Upstream API key (never served; denied to the web)
router.php           Router for `php -S`; mirrors .htaccess deny rules
lib/                 EmnexApi, HttpClient, SqliteCache, FileCache, bootstrap helpers, View helpers
views/               Layout + page templates (server-rendered HTML)
assets/css/app.css   Whole site styling (dark theme, accent #39d353)
assets/js/           app.js (runtime) + per-page modules (home, browse, anime, watch, search, schedule, watchlist)
assets/vendor/       hls.min.js (vendored, ~600 KB)
storage/             cache.sqlite (metadata), cache/ (stream sources), logs/
probe/               Saved raw API responses used as shape references during development (denied to web)
docs/                Architecture, contracts and porting guide — read before rewriting
```

## Documentation index

| Doc | Purpose |
| --- | --- |
| `docs/01-architecture.md` | Request lifecycle, layer responsibilities, module boundaries |
| `docs/02-routes-and-api.md` | Every public route + `api.php` action + upstream API shape table |
| `docs/03-caching.md` | Both cache layers: TTLs, locks, stale-if-error, negative cache, quotas |
| `docs/04-frontend.md` | `window.EMNX`, JS module contracts, `data-*` hooks, localStorage schemas |
| `docs/05-porting-guide.md` | Checklist + invariant list for rewriting this onto another (procedural) PHP framework |
| `docs/06-player.md` | Video player architecture, controls, DOM events, and autoplay |
