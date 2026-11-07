# 02 · Routes & API contracts

Three contract tables: public HTML routes, the internal JSON API the browser uses, and the upstream EmnexAnime API shapes the service layer maps to.

## 1. Public HTML routes (`index.php`)

All routes are pretty URLs under `BASE_PATH`. Without mod_rewrite, the legacy form `/?p=<name>&…` maps to the same handlers.

| Route | Handler logic | View | Cache-Control | Notes |
| --- | --- | --- | --- | --- |
| `/` | `EmnexApi::home()`; unwrap `spotlight` (10 items) → hero (6, banner-first) and `sections{trending,seasonal,popular,topRated,upcoming}` (sliced to 18 each, mapped to `browse/…` links) | `home` + `home.js` | `public, max-age=60, SWR=240` | OG image = hero banner |
| `/random` | pick random spotlight (or trending) | 302 to `/anime/{id}` | — | fun dice link |
| `/anime/{id}` | `anime($id)` detail | `anime` + `anime.js` | `public, max-age=120, SWR=600` | JSON-LD `TVSeries` w/ rating, genres, dates |
| `/watch/{id}/{ep}` `?type=sub\|dub` | `anime($id)` (for context data), episode clamped ≥ 0 | `watch` + hls.js + `watch.js` | `private, no-store` | `noIndex`; legacy `/anime/{id}/watch/{ep}` → 302 here |
| `/browse` | 302 → `/browse/trending` | — | — | — |
| `/browse/{type}` | type ∈ `trending`, `popular`, `recent`, `seasonal`, `top-rated`, `upcoming`; `top-rated`/`upcoming` are served from the cached home payload (`home:topRated` feed, single page); `seasonal` gets season/year filters (defaults = current season) | `browse` + `browse.js` | `public, max-age=60, SWR=300` | feeds otherwise page through `/api/trending|popular|recent|seasonal` |
| `/genre/{name}` | `feed('genre', …)` with URL-decoded name (≤ 40 chars) | `browse` | `public, max-age=60, SWR=300` | back link to genre index |
| `/genres` | `genres()` | `genres` | `public, max-age=300` | plain grid of genre tiles |
| `/schedule` | `schedule()` (whole 7-day payload embedded as `#schedule-data` JSON) | `schedule` + `schedule.js` | `public, max-age=120` | JS groups by local day |
| `/search` `?q=` | empty q → genre tiles + trending starters; else `feed('search', page 1, 50)` | `search` + `browse.js`,`search.js` | `public, max-age=60` | results page participates in infinite scroll |
| `/watchlist` | static shell; JS renders localStorage | `watchlist` + `watchlist.js` | `no-store` | `noIndex` |
| `/api/stats` | combined cache diagnostics (JSON) | — | `no-store` | same payload as `api.php?action=stats` |
| anything else | `error_page(404)` | `error` | `no-store` | `ApiException` → mapped friendly error page |

**Cache debug headers** on HTML routes: `X-Emnex-Cache: HIT|MISS|STALE` and `X-Emnex-Cache-Age: <seconds>` (`cache_meta_headers()`).

### `?p=` legacy map

`home→/`, `genres`, `schedule`, `search`, `watchlist` direct; `?p=anime&id=…`; `?p=watch&id=…&ep=…`; `?p=genre&g=…`; `?p=browse&type=…` (sanitised to `[a-z-]`).

## 2. Internal JSON API (`api.php?action=…`)

Envelope: `{ok:true, data:…, meta:{cache,age,…}}` or `{ok:false, error:"<user-safe message>", meta:{…}}`.

| Action | GET params | Behavior | HTTP cache |
| --- | --- | --- | --- |
| `home` | — | home payload | `max-age=120, SWR=480` |
| `genres` | — | genre list | `max-age=300` |
| `schedule` | — | schedule feed | `max-age=120` |
| `feed` | `type` ∈ `trending,popular,recent,seasonal,genre,search`; `page` 1–1000; `perPage` ≤ 50; `season`,`year`,`genre`,`q` | page of `items` + `pageInfo` | `max-age=60` |
| `detail` | `id` | anime detail | `max-age=120` |
| `episodes` | `id` | `{episodes:[{number,title,thumbnail}]}` | `max-age=300` |
| `characters` | `id` | character list | `max-age=300` |
| `seasons` | `id` | seasons/relations list | `max-age=300` |
| `sources` | `id`, `ep`, `type` (`sub\|dub`), `kind` (`anilist\|mal`), `mal` (fallback id), `refresh=1` (bypass file cache) | playable stream for one episode | **`no-store`** |
| `subtitle` | `u` = URL-encoded subtitle VTT url | raw `text/vtt` passthrough, **host-whitelisted to `*.emnexanime.site`** (SSRF stop) | `max-age=3600` |
| `stats` | — | SQLite + file cache stats | `max-age=0` |
| `ping` | — | `{pong:true, key:<bool has key>}` (never returns the key) | — |

Failure mapping: upstream `400/404/429` pass through; `401/403→502`; other → `502`. `refresh=1` also invalidates the file-cache entry (`FileCache::forget`) before resolving.

## 3. Upstream API reference (as used)

Base `https://api.emnexanime.site` (config `api_base`). Auth via `x-api-key` header (server-side only). Free plan observed limits: **2 rps, 1000 requests/month**.

| Endpoint (GET) | Returned shape (fields actually consumed) | Cache policy (service layer) |
| --- | --- | --- |
| `/api/home` | `spotlight[10]`, `sections[]{key,title,items[24]}`; items = anime-tuple below | `home`: TTL 30 min, stale 6 h |
| `/api/trending`,`/api/popular`,`/api/recent` `?page&perPage` | `items[]`, `pageInfo{hasNextPage,total}` | `list`: 15 min, stale 24 h |
| `/api/seasonal` `?page&perPage&season&year` | same as lists | `list` |
| `/api/genre/{genre}` `?page&perPage` | same as lists | `list` |
| `/api/search/{q}` `?page&perPage` | same as lists | `search`: 15 min, stale 24 h |
| `/api/anime/{id}` | detail below | `detail`: 3 h, stale 7 d |
| `/api/anime/{id}/episodes` | `episodes[{number,title,thumbnail}]` (can be 1000+; One Piece ≈ 73 KB) | `episodes`: 6 h, stale 7 d |
| `/api/anime/{id}/seasons` | `seasons[]{id,title,relation,format,year}` (`relation=CURRENT` marks this title) | `seasons`: 12 h, stale 7 d |
| `/api/anime/{id}/characters` | `characters[]{name,role,image,voiceActor{name}}` | `characters`: 24 h, stale 7 d |
| `/api/genres` | `genres[]` | `genres`: 24 h, stale 7 d |
| `/api/schedule` | `schedule[]{episode, airingAt (unix sec), anime{…}}` | `schedule`: 15 min |
| `/api/sources/{id}/{ep}` `?type=sub\|dub` | source payload below | file cache 180 s (`sources` TTL) |
| `/api/sources/mal/{id}/{ep}` `?type=…` | same | file cache 180 s |
| `/hls?u=…` | manifest proxy **with `Access-Control-Allow-Origin: *`** (verified) | no cache |
| `/sub?u=…` | subtitle / preview proxy | no cache |

### Anime tuple (items/cards)

```jsonc
{
  "id": 195516, "idMal": 61987, "title": "…",            // mirrors titles
  "titles": {"romaji": "…", "english": "…", "native": "…"},
  "type": "anime", "format": "TV|TV_SHORT|MOVIE|OVA|ONA|SPECIAL|MUSIC",
  "status": "RELEASING|FINISHED|NOT_YET_RELEASED|CANCELLED|HIATUS",
  "episodes": 12, "duration": 24, "season": "FALL", "year": 2026,
  "rating": 8.5, "popularity": 110573, "genres": ["…"],
  "poster": "https://…", "color": "#e4a128", "banner": "https://…",
  "isAdult": false
}
```

### Detail adds

`description` (HTML incl. `<br>`), `studios[{name}]`, `trailer{youtube,id}` (‑`youtube` may carry trailing whitespace → sanitize to `[A-Za-z0-9_-]`), `tags[]`, `nextAiringEpisode{airingAt, episode}`, `startDate/endDate{year,month,day}`, `source`, `siteUrl`, plus `seasons[]`, `relations[]`, `recommendations[]` (anime tuples + `relation`), `characters[]`.

### Source payload (`sources`, saved probe: `probe/_api_sources.json`)

```jsonc
{
  "provider": "HD-2", "type": "sub",
  "hls": "https://api.emnexanime.site/hls?u=<b64>",
  "subtitles": [{"lang": "English", "url": "https://api.emnexanime.site/sub?u=<b64>", "default": true}],
  "intro": {"start": 31, "end": 111},
  "outro": {"start": 1376, "end": 1447},
  "thumbnailsVtt": "https://api.emnexanime.site/sub?u=<b64>",
  "image": "https://…/thumbnail.jpg"
}
```

Success requires a non-empty `hls`; upstream errors come back as `{"error": "…"}`. Episodes not yet aired / missing titles answer 404-style errors.

### Server-side fallback algorithm (implement verbatim when porting)

```
sources(id, ep, type, kind, malId, refresh):
  modes = kind==mal ? [["mal", id]]
                    : [["anilist", id]] + (malId>0 && malId!=id ? [["mal", malId]] : [])
  for each (mode, modeId):
      try     return resolveSource(mode, modeId, ep, type, refresh)   // cached
      collect failure message
  throw 404 (if last was 404) else 502 with collected messages
```

`resolveSource` is file-cache-first: fresh → return; concurrent while resolving via lock (see `docs/03-caching.md`); success requires `hls` non-empty; on upstream error prefer stale copy, else store the *negative cache* entry (45 s TTL, 90 s stale) and rethrow.
