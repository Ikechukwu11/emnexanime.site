# 06 · Video Player & Stream Architecture

This document details how the custom video player in `assets/js/watch.js` is structured, how stream resolution works, event lifecycles, and how controls / automation operate.

---

## 1. Overview & Contracts

The player is built as a progressive, vanilla JavaScript module around `<video>` and [hls.js](file:///d:/htdocs/js/emnexanime.site/assets/vendor/hls.min.js) (vendored, zero runtime CDN dependencies).

All media data is fetched same-origin through `api.php`:
1. **Sources resolution (`action=sources`)**:
   - `id`: AniList ID
   - `ep`: Episode number
   - `type`: `dub` (default) or `sub`
   - `mal`: Optional MyAnimeList fallback ID
   - `refresh`: `1` to bypass server file cache
2. **Subtitles proxy (`action=subtitle&u=...`)**:
   - Upstream VTT captions proxied same-origin to prevent browser CORS blocks.
3. **Episode catalog (`action=episodes&id=...`)**:
   - Fetched asynchronously to populate the right-hand episode selection list and episode title metadata.

---

## 2. Audio Track Policy: Dub as Default

- **Default Track**: `dub` is configured as the default audio track across:
  - `bootstrap.php` (`watch_url()` defaults to `?type=dub`)
  - `index.php` (`/watch/{id}/{ep}` defaults `$type = query('type') === 'sub' ? 'sub' : 'dub'`)
  - `views/watch.php` (segmented sub/dub switchers and previous/next episode URLs)
  - `assets/js/watch.js` (`state.type = 'dub'`)
- **Seamless Switching**: Clicking the Sub / Dub segmented switcher intercepts navigation, updates URL parameters (`?type=...`) via `history.replaceState`, and re-resolves the stream without reloading the page.
- **Automatic Fallback**: If a title does not have English dubbing available upstream, the player catches the error, displays an informative toast notification, updates the URL to `?type=sub`, and seamlessly resolves the `sub` stream.

---

## 3. DOM & Control Structure

All player controls and components attach via specific `data-*` attributes:

```html
<div class="player" data-player data-id="..." data-episode="..." data-type="dub" ...>
    <video data-video playsinline preload="metadata"></video>

    <!-- Overlay & Status: pointer-events: none -->
    <div class="player__loader" data-player-loader>
        <div class="player__loader-ring"></div>
        <p data-player-status>Resolving stream…</p>
    </div>

    <!-- Error Screen (shown on unrecoverable failures) -->
    <div class="player__error" data-player-error hidden>
        ...
        <button data-player-retry>Try again</button>
    </div>

    <!-- Center Play/Pause button -->
    <button data-bigplay aria-label="Play"></button>

    <!-- Intro & Outro Skipping -->
    <button data-skip-intro hidden>Skip intro</button>
    <button data-skip-outro hidden>Skip outro</button>

    <!-- Bottom Controls Bar (z-index: 7, pointer-events: auto) -->
    <div class="player__controls" data-controls>
        <input data-seek type="range" min="0" max="1000">
        <button data-playpause type="button"></button>
        <button data-mute type="button"></button>
        <input data-volume type="range">
        <span data-time>0:00 / 0:00</span>
        <select data-speed></select>
        <select data-quality></select>
        <select data-audio></select>
        <select data-subs></select>
        <button data-pip type="button"></button>
        <button data-fullscreen type="button"></button>
    </div>
</div>
```

> [!NOTE]
> All control buttons have explicit `type="button"` attributes to ensure they never trigger unintended form submissions or page reloads.

---

## 4. URL Parameters & Synchronization

The player synchronizes playback options with URL query parameters using `history.replaceState` (without full page reloads):

| Parameter | Values | Purpose |
| --- | --- | --- |
| `type` | `dub` \| `sub` | Audio language stream |
| `sub` | `0..N` \| `off` | Subtitle track index |
| `q` | `auto` \| `0..N` | HLS video quality level |
| `speed` | `0.5`, `0.75`, `1`, `1.25`, `1.5`, `2` | Playback speed |
| `audio` | `0..N` | Alternate HLS audio stream index |
| `autoplay` | `1` \| `0` | Autoplay next episode toggle state |
| `debug` | `1` | Enable player diagnostic event logs |

---

## 5. Custom JavaScript Events (`CustomEvent`)

All events are dispatched on the `[data-player]` element and bubble:

```javascript
const playerEl = document.querySelector('[data-player]');
playerEl.addEventListener('emnx:ended', (e) => {
    console.log('Finished episode:', e.detail.episode);
});
```

| Event Name | `event.detail` Payload | Description |
| --- | --- | --- |
| `emnx:resolving` | `{ type: string, refresh: boolean }` | Stream API lookup initiated |
| `emnx:source` | `{ provider: string, type: string, subs: number }` | Stream successfully resolved |
| `emnx:error` | `string` (error message) | Unrecoverable error encountered |
| `emnx:waiting` | `null` | Video playback stalled / buffering |
| `emnx:playing` | `null` | Video started or resumed playing |
| `emnx:pause` | `null` | Video playback paused |
| `emnx:time` | `{ pos: number, dur: number }` | Current playback position update (throttled 1s) |
| `emnx:speed` | `number` | Playback rate changed (e.g. `1.25`) |
| `emnx:quality` | `string` | Video level changed (`auto` or level index) |
| `emnx:subtitle` | `string \| null` | Subtitle track index or `null` for off |
| `emnx:audio` | `string` | Audio track index changed |
| `emnx:mute` | `boolean` | Sound muted/unmuted |
| `emnx:skip` | `'intro' \| 'outro'` | Skipped an opening/ending window |
| `emnx:autoplay`| `boolean` | Autoplay-next checkbox toggled |
| `emnx:ended` | `{ episode: number, total: number, hasNext: boolean }` | Episode playback reached the end |

---

## 6. Autoplay & Next Episode Flow

1. When the current video fires the standard `ended` event, the player triggers `onEnded()`.
2. Emits the custom `emnx:ended` event.
3. Automatically marks episode progress as 100% watched in `localStorage` (`emnx.episode-progress.v1`).
4. If the "Autoplay next" switch is checked (`[data-autoplay-next]` or stored in `emnx.autoplay.v1`) and a next episode exists (`state.episode < state.total`), the player automatically navigates via the `[data-next-episode]` link.
5. External scripts can also programmatically trigger navigation via:
   ```javascript
   window.EMNX.player.goto(nextEpisodeNumber);
   ```

---

## 7. Global API (`window.EMNX.player`)

Methods available in `window.EMNX.player`:

- `play()`: Start playback.
- `pause()`: Pause playback.
- `toggle()`: Toggle play/pause state.
- `seek(seconds)`: Seek to position in seconds.
- `goto(episodeNumber, type)`: Jump directly to an episode with sub/dub preservation.
- `setSpeed(rate)`: Adjust playback rate (0.5 to 2.0).
- `setQuality(value)`: Set quality (`auto` or level index).
- `setSubtitle(value)`: Change subtitle track (`off` or index).
- `state()`: Inspect current playback state (`animeId`, `episode`, `type`, `pos`, `dur`, `paused`, etc.).

