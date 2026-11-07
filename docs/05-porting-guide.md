# 05 · Porting guide (rewrite onto a custom procedural PHP framework)

The codebase was deliberately built to make this rewrite boring. Everything stateful is already flat data-in/data-out; the classes are thin holders around functions you can lift as-is. This checklist covers *what*, *in what order*, and *what must not change*.

## 0. What "port" means here

- Replace `index.php` (front controller with inline closures) with your framework's router → still a single procedural pipeline: **match route ⇒ fetch+caches ⇒ set headers ⇒ render template**.
- `api.php` becomes a webhook/controller bundle dictating the same envelope `{ok,data,meta}`.
- `lib/*` classes become included function files (`inc/cache_meta.php`, `inc/cache_file.php`, `inc/upstream.php`…). No framework dialect assumptions (no DI, no autoload, no request object) — plain `require`s and superglobals.
- Templates stay as-is: `views/*.php` are just procedural includes using formatters (`title_of()` etc.). If your framework has its own template layer, port them mechanically top-to-bottom.

## 1. Port order (each step independent-ish)

1. **Kernel first** (`lib/bootstrap.php` → your `init.php`): config includes, env toggles, base path detection, helper functions, error handler → exception policy, storage dir creation. *Check without any route: `php -l` + boot a blank template.*
2. **Config table**: keep `config.php` as plain array repository (site_name, api_base, per_page, ttl map…). It is intentionally framework-free — you can ship it unchanged.
3. **Caches**: lift `SqliteCache` → `cache_meta.php` (5 functions listed in `docs/03-caching.md` §3) and `FileCache` → `cache_file.php`. These don't touch anything else; port + smoke test via `api.php?action=stats` equivalent. The schema and stale semantics live in this doc; copy the table.
4. **HTTP client** (`HttpClient` → `http.php`): cURL GET + `x-api-key` injection + timeout policy. Tiny.
5. **Service layer** (`EmnexApi` → `inc/…api.php`): the upstream path table, feed mapping, sources fallback algorithm — all specified in `docs/02-routes-and-api.md` §3. Port **first** among the app layers; write a CLI smoke script that calls `home()`, `feed('search', 1, 5, ['q'=>'frieren'])`, `sources(...)` against the real API (or `.json` fixtures from `probe/`).
6. **View helpers** (`View` class → `inc/view.php`, pure functions): `icon()`, `card()`, `row()`, `posterCard()`, `genreTile()`, `emptyState()`. Zero coupling beyond helpers in the kernel.
7. **Routes** — port and smoke test one by one (order of traffic impact): `/`, `/browse/{type}` (incl. seasonal filters), `/anime/{id}`, `/watch/{id}/{ep}` (+ legacy redirect), `/genre/{g}`, `/genres`, `/schedule`, `/search?[q=]`, `/watchlist`, `/random`, 404/error pages. Reproduce the `Cache-Control` rows from `docs/02-routes-and-api.md` §1 and the `X-Emnex-Cache*` headers.
8. **Internal API**: actions table §2 as-is, including the SSRF-whitelisted subtitle proxy.
9. **Front controller wiring**: `.htaccess` deny list + front-controller rewrite (or equivalents), then the `?p=` legacy translation block.
10. **Front-end**: no changes needed if you keep ① `window.EMNX` injection ② the layout's `data-*` hooks ③ per-route `scripts` arrays ④ caching of `assets/` versioned by mtime. The JS assumes none of the backend's internal structure beyond URL + hook contracts documented in `docs/04-frontend.md`.

## 2. Drop-in inventory (things that port verbatim)

| Asset / layer | Why it ports unchanged |
| --- | --- |
| `assets/css/app.css` | Pure CSS over documented class language; server and JS agree on hooks |
| `assets/js/*.js` | Speaks only `window.EMNX` + fetch + `data-*` hooks |
| `assets/vendor/hls.min.js` | Vendored intentionally (no CDN dependency) |
| `view` templates | They're procedural includes over kernel helpers |
| `keys.txt` handling | One `trim(file_get_contents())` + server deny rules |
| all text formatters | Pure functions (`docs/01-architecture.md` §4 kernel table) |

## 3. Invariants checklist (do-not-break contract)

- [ ] **Key isolation:** upstream key only server-side; deny `keys.txt config.php storage/ lib/ views/ probe/ logs/ *.sqlite* dotfiles` in the server config, AND assert in config load (current guard: `config.php` 403s without `EMNX` constant).
- [ ] **Envelope:** every internal JSON `{ok:true,data,meta}` / `{ok:false,error,meta}` with user-safe error text.
- [ ] **Cache classes:** `HIT | MISS | STALE | BYPASS` surfaced in `meta.cache`, seconds in `meta.age`, plus `X-Emnex-Cache(-Age)` on HTML routes.
- [ ] **TTL table:** identical numbers & stale windows (`docs/03-caching.md` §1) — FE settings and UX copy assume them ("Cached for six hours" note on the episodes panel, 3-minute source cache message in the player meta).
- [ ] **Sources algorithm:** AniList→MAL fallback with collected per-attempt messages (`docs/02-routes-and-api.md` §3); negative cache 45 s/90 s; `refresh=1` bypass.
- [ ] **Sources never cached by the browser** (`no-store`). Subtitle proxy host-whitelist.
- [ ] **URL surface:** `/anime/{id}`, `/watch/{id}/{ep}` (+`?type=dub`), `/browse/{type}` with the six fixed types, `/genre/{utf-8 name}` ≤ 40 chars, legacy `?p=` map.
- [ ] **`window.EMNX` and DOM hooks** unchanged (or `docs/04-frontend.md` updated in the same change-set) — the JS layer breaks silently otherwise.
- [ ] **Card DOM parity** between server renderer and `EMNX.cardHTML` (snapshot the markup once and diff on CI/manual review).
- [ ] **Quota guards:** `perPage ≤ 50`, `page ≤ 1000`, FE ≤ 5 auto-loads then manual click.
- [ ] **Error pages:** mapped friendly statuses (upstream 401/403→502; 429 keeps a "cache has you covered" tone; unknown → 500 + server log, message only when `debug`).

## 4. Test script for the port (acceptance)

Run after each port step; ~10 minutes total with warm caches:

```
php -S 127.0.0.1:8080 router.php        # or your framework's server with equivalent rules
curl -i http://127.0.0.1:8080/                     → 200 HTML (hero markup present)
curl -I http://127.0.0.1:8080/keys.txt             → 403
curl    http://127.0.0.1:8080/api.php?action=ping  → {"ok":true,…}
curl -i http://127.0.0.1:8080/browse/trending      → 200 + X-Emnex-Cache: HIT/MISS
curl    "http://127.0.0.1:8080/api.php?action=feed&type=search&q=frieren&page=2&perPage=20"
curl    http://127.0.0.1:8080/anime/21             → detail; episodes panel placeholder present
curl -I http://127.0.0.1:8080/anime/21/watch/1     → 302 /watch/21/1
curl    http://127.0.0.1:8080/watch/21/1           → watch page, no hls key leakage, scripts listed
curl    "http://127.0.0.1:8080/api.php?action=sources&id=21&ep=1&type=sub&mal=21"   → data.hls non-empty
curl -i http://127.0.0.1:8080/schedule             → 200 (schedule-data JSON embedded)
curl    "http://127.0.0.1:8080/search?q=frieren"   → grid + data-browse-grid=feed search
curl    http://127.0.0.1:8080/watchlist            → shell 200
curl    http://127.0.0.1:8080/api.php?action=stats → sqlite/files stats
```

Then in a browser: home hero advances; search suggestion dropdown appears; anime page lazy-loads episodes on scroll; watch page plays, subtitles attach, timeline moves, progress persists after reload (resume toast), skip-intro appears around 0:31; watchlist add/remove/clear works; continue-watching row fills on home.
(Full manual pass already executed on the current implementation — use it as regression reference.)

## 5. Known extension points (when you're ready)

- `pair` detail/episodes fetch as you get here now: the view renders a placeholder and fetches on scroll; you could server-render the first 20 instead (trade-off: bigger HTML, one more cache read, marginally better no-JS UX).
- `thumbnailsVtt` from the source payload is currently unused — wire click-preview thumbnails in the player when time allows (needs a seekbar sprite parser; served same-origin through the subtitle proxy whitelist).
- A `series`/`watch` "history" page can be built purely on `emnx.progress.v1` with zero backend work.
- If you upgrade to a non-free plan with a higher `rps`, the only numbers to revisit are the config TTL table.
