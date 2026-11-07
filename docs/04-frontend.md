# 04 · Front-end contract

Server-rendered HTML + progressive vanilla JS. Every JS behaviour attaches through `data-*` hooks (no fragile class/ID selectors) and reads its config from `window.EMNX`. This file is the single source of truth for that contract — keep it in sync when porting.

## 1. `window.EMNX` (injected by `views/layout.php`)

```jsonc
{
  "base": "/blog-prefix-or-empty",      // BASE_PATH
  "api": "/api.php",                     // internal JSON API
  "home": "/", "searchUrl": "/search",
  "watchlistUrl": "/watchlist",
  "watchBase": "/watch", "animeBase": "/anime",
  "page": "home|anime|watch|browse|genre|search|schedule|watchlist|genres|error",
  "watchlistKey": "emnx.watchlist.v1",
  "progressKey": "emnx.progress.v1",
  "episodeProgressKey": "emnx.episode-progress.v1"
}
```

Script loading rules (layout):
- `app.js` on every page (defer).
- `assets/vendor/hls.min.js` + `watch.js` when the route includes `watch.js` in its script list.
- Every page declares its additional modules via the route's `scripts` array (`home.js`, `browse.js`, `anime.js`, `search.js`(+`browse.js`), `schedule.js`, `watchlist.js`).

## 2. Global hooks (any page)

| Hook | Element(s) | Behaviour (module) |
| --- | --- | --- |
| `data-watchlist-toggle` + `data-id,data-title,data-poster,data-year,data-format,data-score` | buttons (hero, detail) | toggle saved entry; swaps `data-watchlist-icon` markup between + / filled bookmark; label via `data-watchlist-label` ("Add to watchlist" / "In watchlist"); toast + badge update |
| `data-watchlist-count` | header badge | auto-updates (`hidden` at 0, `99+` cap) |
| `data-site-header` | `<header>` | `.is-scrolled` class past 8 px; `.is-searching` when mobile search open |
| `data-search` / `data-search-input` / `data-search-panel` / `data-search-toggle` | header search | debounced (320 ms) type-ahead via `action=feed&type=search&perPage=8`, `AbortController` per keystroke, results marked `.search-result` (+`.is-selected` keyboard nav), Enter submits, Esc closes, `/` focuses anywhere |
| `data-toasts` | fixed stack | `EMNX.toast(message, kind)` appends `.toast` (auto-remove ~3.2 s) |
| `data-modal`, `data-modal-body`, `data-modal-close` | dialog shell | `EMNX.modal.open(html)` / `.close()`; Esc + backdrop click close; focus restore |
| `data-continue` + `data-continue-scroller` (+`data-continue-clear`) | home & watchlist rows | renders recent progress entries as "continue watching" cards; stays `hidden` when empty |
| `data-rowblock` / `data-row-scroller` / `data-row-prev` / `data-row-next` | horizontal rows | arrow scrolling (±85% view width), arrow `disabled` state at edges |
| `<img>`onerror | everywhere | broken images fade out revealing the `.card__fallback` initials block behind |

## 3. Card markup parity (View::card() ⇄ EMNX.cardHTML())

Both must emit exactly:

```html
<article class="card [extraClass]" style="--card-accent:{color or #39d353}">
  <a class="card__media" href="{animeBase}/{id}" aria-label="{title}">
    <span class="card__fallback" aria-hidden="true">{initials}</span>
    <img src="{poster}" alt="{title} poster" loading="{eager|lazy}" decoding="async">
    <span class="card__shade"></span>
    [rank?] <span class="card__rank">{n}</span>
    [badge?] <span class="card__badge">{text}</span>
    [format?] <span class="card__format">{label}</span>
    [score?] <span class="card__score">{star-svg} {x.x}</span>
    [progress?] <span class="card__progress"><i style="width:{pct}%"></i></span>
  </a>
  [remove?] <button class="card__remove" data-remove-id="{id}">{×}</button>
  <a class="card__play" href="{watchBase}/{id}/{ep}">{play-svg}</a>
  <div class="card__body">
    <a class="card__title" href="{animeBase}/{id}">{title}</a>
    <p class="card__meta">{year · format · eps}</p>
  </div>
</article>
```

Format labels, status labels, meta-line composition are mirrored in JS (`EMNX.cardHTML` / schedule.js helpers) and PHP helpers — keep both in sync or derive from one shared source when porting.

## 4. JSON consumed by JS

| Producer | Consumer | Shape |
| --- | --- | --- |
| `api.php?action=feed…` | `browse.js` | `{ok,data:{items[], pageInfo{hasNextPage,total}},meta}` |
| `api.php?action=episodes` | `anime.js`, `watch.js` | `{ok,data:{episodes:[{number,title,thumbnail}]}}` |
| `api.php?action=sources` | `watch.js` | see `docs/02-routes-and-api.md` §3 |
| `api.php?action=subtitle` | browser `<track src>` | raw `text/vtt` |
| `#schedule-data` | `schedule.js` | `[{airingAt, episode, anime:{id,title\|titles,poster,format,year,episodes,status}}]` |

## 5. Per-page module contracts

### browse.js — paged grids
- Mount: `[data-browse-grid]` with `data-feed, data-page, data-per-page, data-has-next, data-params(JSON)`.
- Fetch `action=feed&type=…&page=next&perPage=…` + whitelisted keys of `data-params` (`q,genre,season,year`).
- Appends `EMNX.cardHTML()` cards; spins `[data-load-spinner]`; `button[data-load-more]` always works; IntersectionObserver on `[data-load-sentinel]` auto-loads **max 5 pages** (then button-driven; a manual click re-arms auto mode).
- End state: `load-area` hidden, `end-note` shown.

### anime.js — detail page
- `[data-episodes][data-id]` + `[data-ep-list]` + `[data-ep-more]` + `[data-ep-note]` + `[data-ep-jump]` + `[data-ep-count]`.
- Lazy load via IntersectionObserver **and** a deterministic scroll/interval fallback (loads when section are within 800 px, also checked at boot). Batches of 100; `Load episodes 101–200` style button; jump-box renders enough batches and flashes the row (`.is-flash`).
- Watched episodes (≥ 92 % progress in `episodeProgress` store) get `.ep-row.is-watched` (strike-through).
- Synopsis `data-synopsis` + `data-synopsis-toggle` (`.is-clamped` toggle, "Read more/less").
- Relations: `[data-relation-item].is-hidden-extra` reveal via `data-relations-more`.
- Trailer: `data-trailer="{youtubeId}"` → modal with `youtube-nocookie` iframe (autoplay+rel=0).
- Countdown: `data-countdown data-at(unix) data-episode` updates `[data-countdown-value]` per second ("2:41:03" → "any second").
- Share: `data-share` → `navigator.share` → clipboard fallback → toast.
- Continue CTA: if show-level progress exists, `.anime-actions .btn--primary` becomes `Continue · Episode {n}` linking to the exact episode.

### watch.js — player
State from `[data-player]` dataset: `data-id, data-mal, data-episode, data-type, data-title, data-poster, data-banner, data-total`.
Resolve on boot (`sources`, no refresh). Failure → `[data-player-error]` + `[data-player-error-text]`; `data-player-retry` re-resolves, `data-reload-stream` re-resolves with `refresh=1`.
hls.js flow: `Hls.isSupported()` → attach/load/config; `MANIFEST_PARSED` builds the Quality menu (Auto + per-level `HEIGHTp`, hidden if <2 levels), Audio menu (hidden if single track); `LEVEL_SWITCHED` annotates "Auto (720p)"; fatal NETWORK/MEDIA errors retry up to `MAX_RETRY=2` (`startLoad` / `recoverMediaError`) then surface error. Safari native path: direct `src=…m3u8`.
Subtitles: `<track kind=subtitles data-kind=sub data-index>` per API entry (proxied through `api.php?action=subtitle&u=…`); `[data-subs]` select swaps `textTracks[i].mode`; default = API's `default:true`.
Timeline & bars: `[data-seek]` (0–1000 range, scrub = `input` event sets currentTime), `[data-played]`/`[data-buffered]` widths, `[data-time]` "m:ss / h:mm:ss".
Buttons/hooks: `[data-playpause]`, `[data-bigplay]`, `[data-mute]`, `[data-volume]`, `[data-speed]`, `[data-quality]`, `[data-audio]`, `[data-pip]`, `[data-fullscreen]`, skip buttons `[data-skip-intro]` / `[data-skip-outro]` (shown inside the API windows, click seeks to `end + 0.2`).
Keyboard (when not typing, no modal): `Space/K` play, `←/→` ±10 s, `F` fullscreen, `M` mute, `N` next-episode link, `P` PiP. Click = play/pause (disambiguated from double-click which is fullscreen). Controls hide after 2.8 s idle while playing (`.is-idle`), re-appear on pointer/key.
State objects:
- `progress` (show level, drives Continue-watching cards) saved every 5 s of playback.
- `episode` progress (`"<id>:<ep>"`) powers resume: on boot, if `pos > 12 s` and percent < 92 %, seeks and toasts "Resumed at …".
- Autoplay next: `[data-autoplay-next]` checkbox persists to `emnx.autoplay.v1`; on `ended` navigates via `[data-next-episode]` link when episode < `data-total`.
Sidebar: `[data-watch-ep-list]` + `[data-watch-ep-filter]` + `[data-watch-ep-more]` (+`[data-watch-ep-count]`, `[data-episode-title]`) lazy-loads the full episode list (batches of 150, debounced numeric/title filter, current episode marked `.is-current`).

### schedule.js
Parses `#schedule-data`, buckets entries by local calendar day, builds `.schedule-tab` (Today/Tmrw/weekday + date number) and `.schedule-day` blocks with `.schedule-item` links. "Live" styling (`schedule-item--live`) for items airing within ±window; countdown labels refresh every 30 s ("airing / in 12m / in 3h / 1d").

### watchlist.js
Renders `EMNX.watchlist.all()` into `[data-watchlist-grid]` via `EMNX.cardHTML(…, {remove:true})`; summary text in `[data-watchlist-summary]`; `[data-watchlist-empty]` toggled; per-card remove (`[data-remove-id]`); two-step arm-to-confirm `[data-watchlist-clear]` (also clears progress).

### search.js
Autofocus `data-search-focus`; records recent searches in `emnx.recent-searches.v1` (form submit). Grid/infinite-scroll relies on `browse.js`.

## 6. localStorage schema (authoritative)

| Key | Type | Written by | Shape |
| --- | --- | --- | --- |
| `emnx.watchlist.v1` | JSON | app.js, watchlist.js | `[{id,title,poster,year,format,score,savedAt}]` (cap 500) |
| `emnx.progress.v1` | JSON | watch.js | `{"<id>":{animeId,title,poster,episode,type,pos,dur,percent,updatedAt,score,color,format}}` (keeper: 40 most recent) |
| `emnx.episode-progress.v1` | JSON | watch.js | `{"<id>:<ep>":{pos,dur,updatedAt}}` (pruned to alive shows) |
| `emnx.autoplay.v1` | JSON | watch.js | `true/false` |
| `emnx.volume.v1` | JSON | watch.js | `0…1` |
| `emnx.rate.v1` | JSON | watch.js | `0.5…2` |
| `emnx.recent-searches.v1` | JSON | search.js | `["term", …]` (cap 12) |

All access goes through `EMNX.store` (try/catch-wrapped JSON, private-mode safe). Removing progress for a show is automatic when it's removed from the watchlist.
