/* =============================================================================
 * EmnexAnime — watch.js  (v2)
 * Stream resolution + custom HLS player + offline-lite state.
 *
 * SERVER CONTRACT (all same-origin through api.php; the API key never leaves PHP):
 *   GET api.php?action=sources&id&ep&type=sub|dub&mal&refresh=0|1
 *     → { provider, type, hls,
 *         subtitles:[{lang,label?,srclang?,url,default?}],
 *         intro:{start,end}|null, outro:{start,end}|null,
 *         thumbnailsVtt, image }
 *   GET api.php?action=episodes&id  → { episodes:[{number,title,thumbnail}] }
 *
 * URL PARAMS (kept in sync on control changes via history.replaceState):
 *   type=dub|sub      audio track language (dub is the site default)
 *   sub=<i>|off       subtitle track index or "off"
 *   q=auto|<level>    hls quality level
 *   speed=<rate>      playback rate (0.5…2)
 *   audio=<i>         hls audio track index
 *   autoplay=1|0      autoplay-next switch
 *   debug=1           console.debug + meta-panel event log
 *
 * PLAYER EVENTS (CustomEvents dispatched on [data-player], see docs/06-player.md):
 *   emnx:resolving emnx:source emnx:error emnx:waiting emnx:playing
 *   emnx:pause emnx:time emnx:speed emnx:quality emnx:subtitle emnx:skip emnx:ended
 * GLOBAL API: window.EMNX.player = { play, pause, toggle, seek, goto,
 *   setSpeed, setQuality, setSubtitle, state }
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    const player = EMNX.$('[data-player]');
    if (!player) return;

    const video = EMNX.$('[data-video]', player);
    const loader = EMNX.$('[data-player-loader]', player);
    const statusText = EMNX.$('[data-player-status]', loader);
    const errorBox = EMNX.$('[data-player-error]', player);
    const errorText = EMNX.$('[data-player-error-text]', errorBox);
    const bigplay = EMNX.$('[data-bigplay]', player);
    const seekBar = EMNX.$('[data-seek]', player);
    const playedBar = EMNX.$('[data-played]', player);
    const bufferedBar = EMNX.$('[data-buffered]', player);
    const timeEl = EMNX.$('[data-time]', player);
    const playBtn = EMNX.$('[data-playpause]', player);
    const muteBtn = EMNX.$('[data-mute]', player);
    const volumeSel = EMNX.$('[data-volume]', player);
    const speedSel = EMNX.$('[data-speed]', player);
    const qualitySel = EMNX.$('[data-quality]', player);
    const audioSel = EMNX.$('[data-audio]', player);
    const subsSel = EMNX.$('[data-subs]', player);
    const pipBtn = EMNX.$('[data-pip]', player);
    const fsBtn = EMNX.$('[data-fullscreen]', player);
    const skipIntro = EMNX.$('[data-skip-intro]', player);
    const skipOutro = EMNX.$('[data-skip-outro]', player);
    const retryBtn = EMNX.$('[data-player-retry]', player);
    const reloadBtn = EMNX.$('[data-reload-stream]');
    const metaEl = EMNX.$('[data-source-meta]');
    const epTitle = EMNX.$('[data-episode-title]');
    const autoplayInput = EMNX.$('[data-autoplay-next]');

    const state = {
        id: parseInt(player.dataset.id, 10) || 0,
        mal: parseInt(player.dataset.mal, 10) || 0,
        episode: parseInt(player.dataset.episode, 10) || 1,
        type: player.dataset.type === 'sub' ? 'sub' : 'dub',
        title: player.dataset.title || '',
        total: parseInt(player.dataset.total, 10) || 0,
        hls: null,
        intro: null,
        outro: null,
        resolving: false,
        fellBack: false,      // dub→sub fallback already applied
        resumeDone: false,
        attempts: 0,
        subs: []              // [{label, srclang, url}]
    };
    const MAX_RETRY = 2;

    /* ------------------------------------------------------------- tracker */
    const DEBUG = (() => {
        try {
            const q = new URLSearchParams(location.search);
            return q.get('debug') === '1' || EMNX.store.get('emnx.debug.v1', false) || EMNX.debug === true;
        } catch (e) { return false; }
    })();

    function track(event, detail) {
        if (DEBUG && window.console && console.debug) {
            console.debug('[player] ' + event, detail === undefined ? '' : detail);
        }
        if (metaEl && DEBUG) {
            metaEl.hidden = false;
            const t = new Date().toTimeString().slice(0, 8);
            metaEl.textContent = t + ' · ' + event + (detail !== undefined ? ' · ' + String(detail).slice(0, 120) : '');
        }
    }

    function emit(name, detail) {
        try { player.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) { /* noop */ }
        track('event:' + name, detail);
    }

    /* ------------------------------------------------------------ url sync */
    function currentParams() {
        try { return new URLSearchParams(location.search); }
        catch (e) { return new URLSearchParams(); }
    }
    /* Merge {key: value|null|undefined} into the query string without navigating. */
    function syncUrl(updates) {
        try {
            const params = currentParams();
            Object.keys(updates).forEach(function (key) {
                const value = updates[key];
                if (value === null || value === undefined || value === '') params.delete(key);
                else params.set(key, String(value));
            });
            const qs = params.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
        } catch (e) { /* noop */ }
    }
    function param(name, fallback) {
        const v = currentParams().get(name);
        return v === null || v === '' ? fallback : v;
    }

    /* ------------------------------------------------------- small helpers */
    function showLoader(text) {
        state.resolving = true;
        player.classList.add('is-resolving');
        player.classList.remove('has-error');
        if (errorBox) errorBox.hidden = true;
        if (loader) {
            loader.hidden = false;
            loader.classList.remove('is-hidden');
            if (statusText && text) statusText.textContent = text;
        }
    }
    function hideLoader() {
        state.resolving = false;
        player.classList.remove('is-resolving');
        if (loader) {
            loader.hidden = true;
            loader.classList.add('is-hidden');
        }
    }
    function showError(message) {
        hideLoader();
        player.classList.add('has-error');
        if (errorBox) {
            errorBox.hidden = false;
            if (errorText) errorText.textContent = message;
        }
        emit('emnx:error', message);
    }
    function watchUrl(ep, type) {
        const t = (type || state.type);
        return (EMNX.base || '') + '/watch/' + state.id + '/' + ep + (t === 'dub' ? '?type=dub' : '?type=sub');
    }

    /* ================================================== stream resolution */
    function resolveStream(refresh, typeAttempt) {
        const type = (typeAttempt || state.type);
        hideLoader();
        try { video.pause(); } catch (e) { /* noop */ }
        destroyHls();
        showLoader(refresh ? 'Re-resolving stream…' : ('Resolving ' + type + ' stream…'));

        const qs = { action: 'sources', id: String(state.id), ep: String(state.episode), type: type };
        if (state.mal > 0) qs.mal = String(state.mal);
        if (refresh) qs.refresh = '1';

        emit('emnx:resolving', { type: type, refresh: !!refresh });
        EMNX.fetchJSON(qs)
            .then(function (res) { playSource(res.data || {}); })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                // Dub is the default but not always available; drop to sub once.
                if (type === 'dub' && !state.fellBack) {
                    state.fellBack = true;
                    track('dub-fallback', err.message);
                    EMNX.toast('No dub found for this episode — playing the sub instead.');
                    syncUrl({ type: 'sub' });
                    state.type = 'sub';
                    resolveStream(false, 'sub');
                    return;
                }
                showError(err.message || 'We could not resolve this episode.');
            });
    }

    function destroyHls() {
        if (state.hls) {
            try { state.hls.destroy(); } catch (e) { /* noop */ }
            state.hls = null;
        }
        video.removeAttribute('src');
        try { video.load(); } catch (e) { /* noop */ }
    }

    function playSource(src) {
        if (!src || !src.hls) {
            showError('No playable stream was found for this episode.');
            return;
        }
        state.intro = (src.intro && typeof src.intro.start === 'number') ? src.intro : null;
        state.outro = (src.outro && typeof src.outro.start === 'number') ? src.outro : null;
        updateSkipButtons(-1);
        if (metaEl) {
            metaEl.hidden = false;
            metaEl.textContent = 'Source ' + (src.provider || 'default') + ' · ' + (src.type || state.type)
                + ' · signed stream URL (server-cached 3 min)';
        }

        buildSubtitles(src);
        emit('emnx:source', { provider: src.provider || '', type: src.type || state.type, subs: state.subs.length });
        attachHls(src);
    }

    /* Subtitles: same-origin <track> elements through api.php proxy. */
    function buildSubtitles(src) {
        if (!subsSel || !video) return;
        const subs = (src.subtitles || []).filter(function (t) { return t && (t.url || t.src); });
        state.subs = subs;
        subsSel.innerHTML = '<option value="off">CC off</option>' + subs.map(function (t, i) {
            const label = t.label || t.lang || ('Sub ' + (i + 1));
            return '<option value="' + i + '">' + EMNX.esc(label) + ' subtitles</option>';
        }).join('');

        video.querySelectorAll('track[data-kind="sub"]').forEach(function (node) { node.remove(); });
        subs.forEach(function (t, i) {
            const node = document.createElement('track');
            node.kind = 'subtitles';
            node.label = t.label || t.lang || ('Sub ' + (i + 1));
            node.srclang = t.srclang || t.lang || 'en';
            node.src = (EMNX.api || '/api.php') + '?action=subtitle&u=' + encodeURIComponent(t.url || t.src);
            node.setAttribute('data-kind', 'sub');
            node.setAttribute('data-index', String(i));
            if (t.default) node.default = true;
            video.appendChild(node);
        });

        // Preference order: URL ?sub= → API default track → off.
        const wantSub = param('sub', null);
        if (wantSub !== null) {
            subsSel.value = (wantSub === 'off' || wantSub === '-1' || isNaN(parseInt(wantSub, 10))) ? 'off' : String(Math.max(0, Math.min(subs.length - 1, parseInt(wantSub, 10))));
        } else if (video.querySelector('track[data-kind="sub"][default]')) {
            subsSel.value = video.querySelector('track[data-kind="sub"][default]').getAttribute('data-index');
        } else {
            subsSel.value = 'off';
        }
        applySubSelection();
        syncUrl({ sub: subsSel.value === 'off' ? null : subsSel.value });
    }

    function applySubSelection() {
        if (!subsSel || !video.textTracks) return;
        const want = subsSel.value;
        for (let i = 0; i < video.textTracks.length; i++) {
            const track = video.textTracks[i];
            if (track.kind !== 'subtitles') continue;
            track.mode = String(track.getAttribute('data-index')) === want ? 'showing' : 'disabled';
        }
        emit('emnx:subtitle', want === 'off' ? null : want);
    }

    /* ============================================================ attach */
    function attachHls(src) {
        const resume = resumeFrom();
        state.resumeDone = false;

        if (window.Hls && window.Hls.isSupported()) {
            const hls = new Hls({
                capLevelToPlayerSize: true,
                maxBufferLength: 30,
                backBufferLength: 60,
                enableWorker: true
            });
            state.hls = hls;
            hls.on(Hls.Events.MEDIA_ATTACHED, function () { hls.loadSource(src.hls); });
            hls.on(Hls.Events.MANIFEST_PARSED, function () {
                hideLoader();
                buildQualityMenu(hls);
                buildAudioMenu(hls);
                applyResume(resume);
                track('levels', (hls.levels || []).map(function (l) { return l.height; }).join(','));
            });
            hls.on(Hls.Events.LEVEL_SWITCHED, function (event, data) { qualitySync(data.level); });
            hls.on(Hls.Events.ERROR, function (event, data) {
                if (!data.fatal) { track('hls-error', data.details); return; }
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR && state.attempts < MAX_RETRY) {
                    state.attempts += 1;
                    showLoader('Network hiccup — retrying… (' + state.attempts + '/' + MAX_RETRY + ')');
                    hls.startLoad();
                    return;
                }
                if (data.type === Hls.ErrorTypes.MEDIA_ERROR && state.attempts < MAX_RETRY) {
                    state.attempts += 1;
                    hls.recoverMediaError();
                    return;
                }
                showError('The video stream failed to load. Try "Reload stream" or a different episode.');
            });
            hls.attachMedia(video);
        } else if (video.canPlayType('application/vnd.apple.mpegurl') || video.canPlayType('application/x-mpegURL')) {
            // Safari / iOS native HLS.
            video.src = src.hls;
            video.addEventListener('loadedmetadata', function () {
                hideLoader();
                applyResume(resume);
            }, { once: true });
        } else {
            showError('Your browser does not support HLS playback.');
            return;
        }
        attemptPlay();
    }

    function buildQualityMenu(hls) {
        if (!qualitySel) return;
        const levels = hls.levels || [];
        const want = param('q', null);
        qualitySel.innerHTML = '<option value="auto">Auto</option>' + levels.map(function (level, i) {
            const height = level.height ? level.height + 'p' : (Math.round((level.bitrate || 0) / 1000) + ' kbps');
            return '<option value="' + i + '">' + EMNX.esc(height) + '</option>';
        }).join('');
        qualitySel.hidden = levels.length < 2;
        if (want !== null && qualitySel.querySelector('option[value="' + want + '"]')) {
            qualitySel.value = want;
        } else {
            qualitySel.value = 'auto';
        }
        hls.currentLevel = qualitySel.value === 'auto' ? -1 : Number(qualitySel.value);
    }

    function buildAudioMenu(hls) {
        if (!audioSel) return;
        const tracks = (hls.audioTracks || []).map(function (track, i) {
            return { i: i, name: track.name || track.lang || ('Audio ' + (i + 1)) };
        });
        audioSel.innerHTML = tracks.map(function (track) {
            return '<option value="' + track.i + '">' + EMNX.esc(track.name) + '</option>';
        }).join('');
        audioSel.hidden = tracks.length < 2;
        const want = param('audio', null);
        if (want !== null && tracks[Number(want)]) {
            hls.audioTrack = Number(want);
            audioSel.value = want;
        } else {
            audioSel.value = String(hls.audioTrack);
        }
    }

    function qualitySync(activeLevel) {
        if (!qualitySel) return;
        const option = qualitySel.querySelector('option[value="auto"]');
        if (!option) return;
        const levels = (state.hls && state.hls.levels) || [];
        const level = Number.isInteger(activeLevel) ? levels[activeLevel] : null;
        if (level && level.height) option.textContent = 'Auto (' + level.height + 'p)';
    }

    /* ===================================================== resume progress */
    function resumeFrom() {
        const entry = EMNX.progress.episode(state.id, state.episode);
        if (!entry || !(entry.pos > 12) || !entry.dur) return 0;
        if (entry.pos / entry.dur >= 0.92) return 0;
        return entry.pos;
    }

    function applyResume(savedPos) {
        if (!savedPos) return;
        const finish = function () {
            if (state.resumeDone) return;
            if (!isFinite(video.duration) || video.duration <= 0) return;
            if (savedPos < video.duration - 5) {
                try { video.currentTime = savedPos; } catch (e) { /* noop */ }
                EMNX.toast('Resumed at ' + EMNX.fmtTime(savedPos));
                track('resume', EMNX.fmtTime(savedPos));
            }
            state.resumeDone = true;
        };
        video.addEventListener('loadedmetadata', finish, { once: true });
        finish();
    }

    /* ========================================================= playback UI */
    function attemptPlay() {
        const promise = video.play();
        if (promise && promise.catch) {
            promise.then(function () {
                emit('emnx:playing', null);
            }).catch(function () {
                track('autoplay-blocked', null);
                // Autoplay blocked: the big play button is available.
            });
        }
    }

    function togglePlay() {
        if (video.paused) attemptPlay(); else video.pause();
    }

    function updatePlayButton() {
        const paused = video.paused;
        player.classList.toggle('is-playing', !paused);
        emit(paused ? 'emnx:pause' : 'emnx:playing', null);
        if (playBtn) {
            playBtn.innerHTML = paused
                ? '<svg class="icon" width="19" height="19" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" fill="currentColor"/></svg>'
                : '<svg class="icon" width="19" height="19" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.4v14H7zM13.6 5H17v14h-3.6z" fill="currentColor"/></svg>';
            playBtn.setAttribute('aria-label', paused ? 'Play' : 'Pause');
        }
    }

    let lastSave = 0;
    let lastEmit = 0;
    function onTimeUpdate() {
        const dur = isFinite(video.duration) ? video.duration : 0;
        const pos = video.currentTime || 0;
        updateTimeline(pos, dur);
        updateSkipButtons(pos);
        const now = Date.now();
        if (now - lastEmit > 1000) { lastEmit = now; emit('emnx:time', { pos: Math.round(pos), dur: Math.round(dur) }); }
        if (dur > 0 && now - lastSave > 5000) {
            lastSave = now;
            saveProgress(pos, dur);
        }
    }

    function updateTimeline(pos, dur) {
        if (seekBar && dur > 0) {
            seekBar.value = String(Math.round((pos / dur) * 1000));
            seekBar.setAttribute('aria-valuetext', EMNX.fmtTime(pos) + ' of ' + EMNX.fmtTime(dur));
        }
        if (playedBar) playedBar.style.width = dur > 0 ? (pos / dur) * 100 + '%' : '0%';
        if (timeEl) {
            const total = dur > 0 ? EMNX.fmtTime(dur) : '—';
            timeEl.innerHTML = EMNX.esc(EMNX.fmtTime(pos)) + ' <span>/</span> ' + EMNX.esc(total);
        }
    }

    function updateBuffered() {
        if (!bufferedBar || !video.buffered || !video.buffered.length) return;
        const end = video.buffered.end(video.buffered.length - 1);
        const dur = video.duration || 0;
        bufferedBar.style.width = dur > 0 ? Math.min(100, (end / dur) * 100) + '%' : '0%';
    }

    function saveProgress(pos, dur) {
        const percent = dur > 0 ? (pos / dur) * 100 : 0;
        EMNX.progress.saveEpisode(state.id, state.episode, pos, dur);
        const previous = EMNX.progress.get(state.id);
        EMNX.progress.save({
            animeId: state.id,
            title: state.title,
            poster: player.dataset.poster || (previous && previous.poster) || '',
            episode: state.episode,
            type: state.type,
            pos: Math.floor(pos),
            dur: Math.floor(dur),
            percent: percent,
            score: (previous && previous.score) || '',
            color: (previous && previous.color) || '',
            format: (previous && previous.format) || ''
        });
    }

    /* -------------------------------------------------------- skip windows */
    function updateSkipButtons(pos) {
        const inIntro = state.intro && pos >= state.intro.start && pos < state.intro.end - 2;
        const inOutro = state.outro && pos >= state.outro.start && pos < state.outro.end - 2;
        if (skipIntro) skipIntro.hidden = !inIntro;
        if (skipOutro) skipOutro.hidden = !inOutro;
    }
    function doSkip(which) {
        const win = which === 'intro' ? state.intro : state.outro;
        if (win && typeof win.end === 'number') {
            try { video.currentTime = win.end + 0.2; } catch (e) { /* noop */ }
            if (skipIntro) skipIntro.hidden = true;
            if (skipOutro) skipOutro.hidden = true;
            emit('emnx:skip', which);
            track('skip-' + which, 'to ' + EMNX.fmtTime(win.end + 0.2));
        }
    }
    skipIntro && skipIntro.addEventListener('click', function () { doSkip('intro'); });
    skipOutro && skipOutro.addEventListener('click', function () { doSkip('outro'); });

    /* ===================================================== episode sidebar */
    function setupEpisodeSidebar() {
        const listEl = EMNX.$('[data-watch-ep-list]');
        if (!listEl) return;
        const moreBtn = EMNX.$('[data-watch-ep-more]');
        const filter = EMNX.$('[data-watch-ep-filter]');
        const countEl = document.querySelector('[data-watch-ep-count]');
        const BATCH = 150;
        let all = null;
        let rendered = 0;
        let query = '';

        EMNX.fetchJSON({ action: 'episodes', id: String(state.id) })
            .then(function (res) {
                all = (res.data.episodes || []).map(function (ep) {
                    return {
                        number: Number(ep.number || 0),
                        title: String(ep.title || '').trim(),
                        thumbnail: String(ep.thumbnail || '')
                    };
                }).filter(function (ep) { return ep.number > 0; })
                    .sort(function (a, b) { return a.number - b.number; });
                if (countEl) countEl.textContent = String(all.length);
                if (epTitle) {
                    const cur = all.find(function (ep) { return ep.number === state.episode; });
                    if (cur) epTitle.textContent = 'Episode ' + cur.number + (cur.title ? ' — ' + cur.title : '');
                }
                render(true);
            })
            .catch(function () {
                listEl.innerHTML = '<div class="ep-placeholder">Episode list is unavailable right now.</div>';
            });

        function matches(ep) {
            if (!query) return true;
            const q = query.toLowerCase();
            return String(ep.number).includes(q) || ep.title.toLowerCase().includes(q);
        }

        function row(ep) {
            const isCurrent = ep.number === state.episode;
            const seen = EMNX.progress.episode(state.id, ep.number);
            const done = seen && seen.dur > 0 && seen.pos / seen.dur > 0.92;
            return '<a class="ep-row' + (isCurrent ? ' is-current' : '') + (done ? ' is-watched' : '') + '" href="' + EMNX.esc(watchUrl(ep.number)) + '"> '
                + '<span class="ep-row__num">' + ep.number + '</span>'
                + '<span class="ep-row__thumb"><span class="card__fallback" aria-hidden="true">' + ep.number + '</span>'
                + (ep.thumbnail ? '<img src="' + EMNX.esc(ep.thumbnail) + '" alt="" loading="lazy" decoding="async">' : '')
                + '</span>'
                + '<span class="ep-row__text"><span class="ep-row__title">' + EMNX.esc(ep.title || 'Episode ' + ep.number) + '</span>'
                + '<span class="ep-row__sub">Episode ' + ep.number + (done ? ' · watched' : '') + '</span></span>'
                + '<span class="ep-row__play">' + playIcon(15) + '</span></a>';
        }

        function render(reset) {
            if (!all) return;
            if (reset) { listEl.innerHTML = ''; rendered = 0; }
            const pool = all.filter(matches);
            const to = Math.min(rendered + BATCH, pool.length);
            const frag = document.createElement('template');
            frag.innerHTML = pool.slice(rendered, to).map(row).join('');
            while (frag.content.firstChild) listEl.appendChild(frag.content.firstChild);
            rendered = to;
            if (moreBtn) moreBtn.hidden = rendered >= pool.length;
        }

        filter && filter.addEventListener('input', function () {
            query = filter.value.trim();
            render(true);
        });
        moreBtn && moreBtn.addEventListener('click', function () { render(false); });
    }

    function playIcon(size) {
        return '<svg class="icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" fill="currentColor"/></svg>';
    }

    /* =========================================================== controls */

    /* --- seek bar --- */
    if (seekBar) {
        seekBar.addEventListener('input', function () {
            const dur = video.duration || 0;
            if (dur > 0) {
                if (playedBar) playedBar.style.width = (Number(seekBar.value) / 10) + '%';
                try { video.currentTime = (Number(seekBar.value) / 1000) * dur; } catch (e) { /* noop */ }
            }
        });
    }

    /* --- play / mute / volume / speed --- */
    playBtn && playBtn.addEventListener('click', togglePlay);
    bigplay && bigplay.addEventListener('click', togglePlay);

    (function restoreVolume() {
        const saved = Number(EMNX.store.get('emnx.volume.v1', 1));
        video.volume = isNaN(saved) ? 1 : Math.max(0, Math.min(1, saved));
        if (volumeSel) volumeSel.value = String(video.volume);
        syncMuteButton();
    })();
    function syncMuteButton() {
        if (muteBtn) {
            muteBtn.innerHTML = (video.muted || video.volume === 0)
                ? '<svg class="icon" width="19" height="19" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4Z" fill="currentColor"/><path d="m16 9 5 6M21 9l-5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
                : '<svg class="icon" width="19" height="19" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4Z" fill="currentColor"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
        }
    }
    muteBtn && muteBtn.addEventListener('click', function () {
        video.muted = !video.muted;
        if (video.muted && video.volume === 0) video.volume = 0.5;
        syncMuteButton();
        if (volumeSel) volumeSel.value = String(video.volume);
        emit('emnx:mute', video.muted);
    });
    volumeSel && volumeSel.addEventListener('input', function () {
        video.volume = Number(volumeSel.value);
        video.muted = video.volume === 0;
        EMNX.store.set('emnx.volume.v1', video.volume);
        syncMuteButton();
    });
    video.addEventListener('volumechange', function () {
        syncMuteButton();
        if (!video.muted) EMNX.store.set('emnx.volume.v1', video.volume);
    });

    speedSel && speedSel.addEventListener('change', function () {
        const rate = Number(speedSel.value) || 1;
        video.playbackRate = rate;
        EMNX.store.set('emnx.rate.v1', rate);
        syncUrl({ speed: rate === 1 ? null : speedSel.value });
        emit('emnx:speed', rate);
    });
    (function restoreRate() {
        const urlRate = Number(param('speed', null));
        const stored = Number(EMNX.store.get('emnx.rate.v1', 1));
        const allowed = [0.5, 0.75, 1, 1.25, 1.5, 2];
        let rate = (!isNaN(urlRate) && allowed.includes(urlRate)) ? urlRate : (allowed.includes(stored) ? stored : 1);
        if (param('speed', null) === null && stored && allowed.includes(stored) && stored !== 1) {
            rate = stored; // no URL param: persisted preference wins
            syncUrl({ speed: speedSel && String(stored) });
        }
        if (speedSel) speedSel.value = String(rate);
        video.playbackRate = rate;
    })();

    /* --- quality / audio / subtitles --- */
    qualitySel && qualitySel.addEventListener('change', function () {
        if (!state.hls) return;
        state.hls.currentLevel = qualitySel.value === 'auto' ? -1 : Number(qualitySel.value);
        syncUrl({ q: qualitySel.value === 'auto' ? null : qualitySel.value });
        qualitySync(state.hls.currentLevel);
        emit('emnx:quality', qualitySel.value);
    });
    audioSel && audioSel.addEventListener('change', function () {
        if (state.hls) state.hls.audioTrack = Number(audioSel.value);
        syncUrl({ audio: audioSel.value });
        emit('emnx:audio', audioSel.value);
    });
    subsSel && subsSel.addEventListener('change', function () {
        applySubSelection();
        syncUrl({ sub: subsSel.value === 'off' || subsSel.value === '-1' ? null : subsSel.value });
    });

    /* --- PiP / fullscreen --- */
    pipBtn && pipBtn.addEventListener('click', function () {
        if (document.pictureInPictureElement) {
            document.exitPictureInPicture && document.exitPictureInPicture();
            return;
        }
        if (video.requestPictureInPicture) {
            video.requestPictureInPicture().catch(function () {
                EMNX.toast('Picture-in-picture is unavailable.', 'error');
            });
        }
    });
    function toggleFullscreen() {
        if (document.fullscreenElement === player) {
            document.exitFullscreen && document.exitFullscreen();
        } else if (player.requestFullscreen) {
            player.requestFullscreen();
        } else if (video.webkitEnterFullscreen) {
            video.webkitEnterFullscreen();
        }
    }
    fsBtn && fsBtn.addEventListener('click', toggleFullscreen);
    document.addEventListener('fullscreenchange', function () {
        player.classList.toggle('is-fullscreen', document.fullscreenElement === player);
    });

    /* --- retry / reload --- */
    retryBtn && retryBtn.addEventListener('click', function () {
        state.attempts = 0;
        state.fellBack = false;
        resolveStream(false);
    });
    reloadBtn && reloadBtn.addEventListener('click', function () {
        state.attempts = 0;
        resolveStream(true);
    });

    /* --- video element events --- */
    let waitingTimer = null;
    video.addEventListener('play', updatePlayButton);
    video.addEventListener('pause', updatePlayButton);
    video.addEventListener('timeupdate', onTimeUpdate);
    video.addEventListener('progress', updateBuffered);
    video.addEventListener('waiting', function () {
        if (video.paused) return;
        // Only surface "Buffering…" if the stall lasts a moment — not a
        // permanently blocking overlay (and the loader never traps clicks).
        clearTimeout(waitingTimer);
        waitingTimer = setTimeout(function () { showLoader('Buffering…'); }, 350);
        emit('emnx:waiting', null);
    });
    ['playing', 'canplay'].forEach(function (event) {
        video.addEventListener(event, function () {
            clearTimeout(waitingTimer);
            if (!state.resolving) hideLoader();
        });
    });
    video.addEventListener('loadedmetadata', function () {
        updateTimeline(video.currentTime, video.duration);
    });
    video.addEventListener('ended', onEnded);
    video.addEventListener('click', function () {
        if (video._clickTimer) { clearTimeout(video._clickTimer); video._clickTimer = null; toggleFullscreen(); return; }
        video._clickTimer = setTimeout(function () { video._clickTimer = null; togglePlay(); }, 220);
    });
    video.addEventListener('dblclick', toggleFullscreen);

    function onEnded() {
        emit('emnx:ended', { episode: state.episode, total: state.total, hasNext: hasNext() });
        const entry = { pos: video.duration || 0, dur: video.duration || 0 };
        saveProgress(entry.pos, entry.dur);
        player.classList.remove('is-playing');
        updatePlayButton();

        const wantNext = autoplayInput ? autoplayInput.checked : false;
        if (wantNext && hasNext()) {
            const link = document.querySelector('[data-next-episode]');
            if (link && !link.hasAttribute('aria-disabled')) {
                EMNX.toast('Autoplaying next episode…');
                track('autoplay-next', state.episode + 1);
                link.click();
                return;
            }
        }
    }
    function hasNext() {
        return !state.total || state.episode < state.total;
    }

    /* --- autoplay toggle persistence (URL ?autoplay wins once) --- */
    if (autoplayInput) {
        const urlAuto = param('autoplay', null);
        autoplayInput.checked = urlAuto !== null ? urlAuto === '1' : !!EMNX.store.get('emnx.autoplay.v1', false);
        if (urlAuto !== null) syncUrl({ autoplay: null }); // reflect in-truth only in the checkbox afterwards
        autoplayInput.addEventListener('change', function () {
            EMNX.store.set('emnx.autoplay.v1', autoplayInput.checked);
            emit('emnx:autoplay', autoplayInput.checked);
        });
    }

    /* --- controls visibility -- CSS toggles on .is-idle --- */
    let idleTimer = null;
    function pokeActivity() {
        player.classList.remove('is-idle');
        player.classList.add('is-controls-open');
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () {
            if (!video.paused) {
                player.classList.add('is-idle');
                player.classList.remove('is-controls-open');
            }
        }, 2800);
    }
    ['mousemove', 'touchstart', 'keydown', 'pointerdown'].forEach(function (evt) {
        player.addEventListener(evt, pokeActivity, { passive: true });
    });
    pokeActivity();

    /* --- keyboard shortcuts --- */
    document.addEventListener('keydown', function (event) {
        const tag = (document.activeElement && document.activeElement.tagName) || '';
        const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
        if (typing || event.ctrlKey || event.metaKey || EMNX.modal.isOpen()) return;

        const dur = video.duration || 0;
        switch (event.key) {
            case ' ': case 'k': case 'K':
                event.preventDefault(); togglePlay(); break;
            case 'ArrowLeft':
                event.preventDefault(); seekRelative(-10); break;
            case 'ArrowRight':
                event.preventDefault(); seekRelative(10); break;
            case 'f': case 'F':
                event.preventDefault(); toggleFullscreen(); break;
            case 'm': case 'M':
                muteBtn && muteBtn.click(); break;
            case 'n': case 'N': {
                const link = document.querySelector('[data-next-episode]');
                link && !link.hasAttribute('aria-disabled') && link.click();
                break;
            }
            case 'p': case 'P':
                pipBtn && pipBtn.click(); break;
            default: break;
        }
    });
    function seekRelative(delta) {
        try { video.currentTime = Math.max(0, Math.min(video.duration || 0, video.currentTime + delta)); }
        catch (e) { /* noop */ }
    }

    /* =================================================== public mini-API */
    EMNX.player = {
        play: attemptPlay,
        pause: function () { try { video.pause(); } catch (e) { /* noop */ } },
        toggle: togglePlay,
        seek: function (sec) { try { video.currentTime = Number(sec) || 0; } catch (e) { /* noop */ } },
        /** Jump to another episode without a full page rewrite: use goto + listener on 'emnx:ended'. */
        goto: function (ep, type) {
            const target = Number(ep) || state.episode + 1;
            const t = (type || state.type);
            location.assign(watchUrl(target, t));
        },
        setSpeed: function (rate) {
            if (!speedSel) return;
            speedSel.value = String(rate);
            speedSel.dispatchEvent(new Event('change'));
        },
        setQuality: function (value) {
            if (!qualitySel) return;
            qualitySel.value = String(value);
            qualitySel.dispatchEvent(new Event('change'));
        },
        setSubtitle: function (value) {
            if (!subsSel) return;
            subsSel.value = String(value);
            subsSel.dispatchEvent(new Event('change'));
        },
        state: function () {
            return {
                animeId: state.id, episode: state.episode, type: state.type,
                total: state.total, pos: video.currentTime, dur: video.duration,
                paused: video.paused, provider: (metaEl && metaEl.textContent) || ''
            };
        }
    };

    /* ============================================================= boot */
    setupEpisodeSidebar();
    // ?type=… from the URL wins over the page default so links stay shareable.
    const urlType = param('type', null);
    if (urlType === 'sub' || urlType === 'dub') { state.type = urlType; }
    resolveStream(false);
})();
